<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Only allow admin & warehouse_manager to view
if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
    header('Location: ../dashboard.php');
    exit();
}

// Fetch all purchase requests
$requests = $pdo->query("
    SELECT pr.*, m.name as manufacturer_name, u.full_name as requested_by_name,
           (SELECT COUNT(*) FROM purchase_request_items pri WHERE pri.purchase_request_id = pr.id) as item_count
    FROM purchase_requests pr
    LEFT JOIN manufacturers m ON pr.manufacturer_id = m.id
    LEFT JOIN users u ON pr.requested_by = u.id
    ORDER BY pr.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Purchase Requests"; ?>
<?php include('includes/head.php'); ?>

<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('../includes/topbar.php'); ?>
    <?php include('../includes/sidebar.php'); ?>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-shopping-bag me-2"></i> Purchase Requests
                            </h4>
                            <div>
                                <a href="request_add.php" class="btn btn-primary">
                                    <i class="bx bx-plus me-1"></i> New Request
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-centered table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Request No.</th>
                                                <th>Manufacturer</th>
                                                <th>Items</th>
                                                <th>Est. Amount</th>
                                                <th>Requested By</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($requests) > 0): ?>
                                                <?php foreach ($requests as $i => $r): ?>
                                                <tr>
                                                    <td><?= $i + 1 ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($r['request_number']) ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($r['manufacturer_name'] ?? 'N/A') ?></td>
                                                    <td>
                                                        <span class="badge bg-info"><?= $r['item_count'] ?></span>
                                                    </td>
                                                    <td>₹<?= number_format($r['total_estimated_amount']) ?></td>
                                                    <td><?= htmlspecialchars($r['requested_by_name']) ?></td>
                                                    <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                                                    <td>
                                                        <?php
                                                        $status = $r['status'];
                                                        $badge = [
                                                            'draft' => 'secondary',
                                                            'sent' => 'info',
                                                            'quotation_received' => 'primary',
                                                            'approved' => 'success',
                                                            'rejected' => 'danger'
                                                        ];
                                                        ?>
                                                        <span class="badge bg-<?= $badge[$status] ?? 'dark' ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $status)) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="request_view.php?id=<?= $r['id'] ?>" 
                                                               class="btn btn-outline-primary" title="View">
                                                                <i class="bx bx-show"></i>
                                                            </a>
                                                            <?php if ($r['status'] === 'draft'): ?>
                                                            <a href="request_edit.php?id=<?= $r['id'] ?>" 
                                                               class="btn btn-outline-warning" title="Edit">
                                                                <i class="bx bx-edit"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if (in_array($r['status'], ['draft', 'sent'])): ?>
                                                            <button onclick="sendToManufacturer(<?= $r['id'] ?>)" 
                                                                    class="btn btn-outline-success" title="Send">
                                                                <i class="bx bx-send"></i>
                                                            </button>
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted py-5">
                                                        <i class="bx bx-package fs-1 d-block mb-3"></i>
                                                        <h5>No purchase requests found</h5>
                                                        <a href="request_add.php" class="btn btn-primary mt-2">
                                                            <i class="bx bx-plus"></i> Create First Request
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include('../includes/footer.php'); ?>
    </div>
</div>

<?php include('../includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function sendToManufacturer(id) {
    if (confirm('Send this request to manufacturer for quotation?')) {
        fetch('request_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + id
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Request sent successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}
</script>

<style>
.table th { font-weight: 600; }
.btn-group-sm .btn { padding: 0.25rem 0.5rem; }
</style>
</body>
</html>