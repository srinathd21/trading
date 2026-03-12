<?php
require_once 'config/database.php';

$id = (int)($_GET['id'] ?? 0);
$role = $_GET['role'] ?? 'viewer';

if ($id <= 0) {
    echo '<div class="alert alert-danger">Invalid visit ID.</div>';
    exit;
}

// Fetch visit details with all staff information
$stmt = $pdo->prepare("
    SELECT sv.*, 
           s.store_code, s.store_name, s.address, s.city, s.phone AS store_phone,
           s.whatsapp_number, s.owner_name, s.gstin,
           u.full_name AS executive_name,
           -- Approval staff
           approver.full_name AS approved_by_name,
           sr.approved_at,
           -- Packing staff
           packer.full_name AS packed_by_name,
           sr.packed_at,
           -- Shipping staff
           shipper.full_name AS shipped_by_name,
           sr.shipped_at,
           sr.tracking_number,
           -- Delivery info
           sr.delivered_at
    FROM store_visits sv
    JOIN stores s ON sv.store_id = s.id
    JOIN users u ON sv.field_executive_id = u.id
    LEFT JOIN (
        SELECT store_visit_id,
               MAX(approved_by) as approved_by,
               MAX(approved_at) as approved_at,
               MAX(packed_by) as packed_by,
               MAX(packed_at) as packed_at,
               MAX(shipped_by) as shipped_by,
               MAX(shipped_at) as shipped_at,
               MAX(delivered_at) as delivered_at,
               GROUP_CONCAT(DISTINCT tracking_number SEPARATOR ', ') as tracking_number
        FROM store_requirements 
        WHERE store_visit_id = ?
        GROUP BY store_visit_id
    ) sr ON sr.store_visit_id = sv.id
    LEFT JOIN users approver ON sr.approved_by = approver.id
    LEFT JOIN users packer ON sr.packed_by = packer.id
    LEFT JOIN users shipper ON sr.shipped_by = shipper.id
    WHERE sv.id = ?
");
$stmt->execute([$id, $id]);
$visit = $stmt->fetch();

if (!$visit) {
    echo '<div class="alert alert-danger">Visit not found.</div>';
    exit;
}

// Fetch requirements with staff details
$items = $pdo->prepare("
    SELECT sr.*, 
           p.product_name, p.product_code, p.retail_price, p.wholesale_price,
           -- Staff details for each requirement
           approver.full_name AS approved_by_name,
           packer.full_name AS packed_by_name,
           shipper.full_name AS shipped_by_name,
           -- Warehouse stock info
           COALESCE(ps.quantity, 0) AS warehouse_stock
    FROM store_requirements sr
    JOIN products p ON sr.product_id = p.id
    LEFT JOIN users approver ON sr.approved_by = approver.id
    LEFT JOIN users packer ON sr.packed_by = packer.id
    LEFT JOIN users shipper ON sr.shipped_by = shipper.id
    LEFT JOIN product_stocks ps ON ps.product_id = p.id 
        AND ps.shop_id = (SELECT id FROM shops WHERE is_warehouse = 1 LIMIT 1)
    WHERE sr.store_visit_id = ?
    ORDER BY 
        FIELD(sr.urgency, 'high', 'medium', 'low'),
        sr.created_at DESC
");
$items->execute([$id]);
$items = $items->fetchAll();

// Calculate totals - FIXED: Check if array is not empty
$total_items = count($items);
$pending_count = $approved_count = $packed_count = $shipped_count = $delivered_count = 0;

if ($items) {
    foreach ($items as $item) {
        switch ($item['requirement_status']) {
            case 'pending':
                $pending_count++;
                break;
            case 'approved':
                $approved_count++;
                break;
            case 'packed':
                $packed_count++;
                break;
            case 'shipped':
                $shipped_count++;
                break;
            case 'delivered':
                $delivered_count++;
                break;
        }
    }
}
?>

<div class="container-fluid">
    <!-- Visit Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="text-primary mb-1">Store Visit Details</h4>
                    <p class="text-muted mb-0">
                        Visit ID: #<?= $visit['id'] ?> • 
                        <?= date('d M Y', strtotime($visit['visit_date'])) ?>
                    </p>
                </div>
                <div class="text-end">
                    <?php if ($visit['visit_type']): ?>
                    <span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $visit['visit_type'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-primary shadow-sm">
                <div class="card-body text-center p-3">
                    <h2 class="text-primary mb-1"><?= $total_items ?></h2>
                    <p class="text-muted mb-0">Total Items</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-warning shadow-sm">
                <div class="card-body text-center p-3">
                    <h2 class="text-warning mb-1"><?= $pending_count ?></h2>
                    <p class="text-muted mb-0">Pending</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-info shadow-sm">
                <div class="card-body text-center p-3">
                    <h2 class="text-info mb-1"><?= $approved_count ?></h2>
                    <p class="text-muted mb-0">Approved</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-primary shadow-sm">
                <div class="card-body text-center p-3">
                    <h2 class="text-primary mb-1"><?= $packed_count ?></h2>
                    <p class="text-muted mb-0">Packed</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-dark shadow-sm">
                <div class="card-body text-center p-3">
                    <h2 class="text-dark mb-1"><?= $shipped_count ?></h2>
                    <p class="text-muted mb-0">Shipped</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card border-success shadow-sm">
                <div class="card-body text-center p-3">
                    <h2 class="text-success mb-1"><?= $delivered_count ?></h2>
                    <p class="text-muted mb-0">Delivered</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- Left Column - Visit Info -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Visit Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless table-sm">
                        <tr>
                            <th width="140">Visit Date</th>
                            <td><strong><?= date('d M Y', strtotime($visit['visit_date'])) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Store</th>
                            <td>
                                <strong>[<?= htmlspecialchars($visit['store_code']) ?>] <?= htmlspecialchars($visit['store_name']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($visit['city']) ?></small>
                            </td>
                        </tr>
                        <tr>
                            <th>Owner</th>
                            <td><?= htmlspecialchars($visit['owner_name'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Address</th>
                            <td><?= nl2br(htmlspecialchars($visit['address'])) ?></td>
                        </tr>
                        <tr>
                            <th>Contact Person</th>
                            <td><?= htmlspecialchars($visit['contact_person'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Phone</th>
                            <td>
                                <?= htmlspecialchars($visit['phone'] ?: $visit['store_phone'] ?: '—') ?>
                                <?php if ($visit['whatsapp_number']): ?>
                                <br><small class="text-success">WhatsApp: <?= htmlspecialchars($visit['whatsapp_number']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($visit['gstin']): ?>
                        <tr>
                            <th>GSTIN</th>
                            <td><?= htmlspecialchars($visit['gstin']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Field Executive</th>
                            <td><?= htmlspecialchars($visit['executive_name']) ?></td>
                        </tr>
                        <tr>
                            <th>Submitted On</th>
                            <td><?= date('d M Y, h:i A', strtotime($visit['created_at'])) ?></td>
                        </tr>
                        <?php if ($visit['next_followup_date']): ?>
                        <tr>
                            <th>Next Follow-up</th>
                            <td><span class="badge bg-warning"><?= date('d M Y', strtotime($visit['next_followup_date'])) ?></span></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right Column - Staff Timeline -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Workflow Timeline</h5>
                </div>
                <div class="card-body">
                    <!-- Approval Section -->
                    <?php if ($visit['approved_by_name']): ?>
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex align-items-center mb-2">
                            <div class="bg-success text-white rounded-circle p-2 me-3">
                                <i class="bx bx-check"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Approved</h6>
                                <small class="text-muted">Requirements approved for processing</small>
                            </div>
                        </div>
                        <div class="ps-5">
                            <p class="mb-1"><strong>Approved by:</strong> <?= htmlspecialchars($visit['approved_by_name']) ?></p>
                            <p class="mb-0 text-muted">
                                <small>On <?= $visit['approved_at'] ? date('d M Y, h:i A', strtotime($visit['approved_at'])) : '—' ?></small>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Packing Section -->
                    <?php if ($visit['packed_by_name']): ?>
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex align-items-center mb-2">
                            <div class="bg-primary text-white rounded-circle p-2 me-3">
                                <i class="bx bx-package"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Packed</h6>
                                <small class="text-muted">Items packed and ready for shipping</small>
                            </div>
                        </div>
                        <div class="ps-5">
                            <p class="mb-1"><strong>Packed by:</strong> <?= htmlspecialchars($visit['packed_by_name']) ?></p>
                            <p class="mb-0 text-muted">
                                <small>On <?= $visit['packed_at'] ? date('d M Y, h:i A', strtotime($visit['packed_at'])) : '—' ?></small>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Shipping Section -->
                    <?php if ($visit['shipped_by_name']): ?>
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex align-items-center mb-2">
                            <div class="bg-dark text-white rounded-circle p-2 me-3">
                                <i class="bx bx-truck"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Shipped</h6>
                                <small class="text-muted">Items shipped to store</small>
                            </div>
                        </div>
                        <div class="ps-5">
                            <p class="mb-1"><strong>Shipped by:</strong> <?= htmlspecialchars($visit['shipped_by_name']) ?></p>
                            <?php if ($visit['tracking_number']): ?>
                            <p class="mb-1"><strong>Tracking #:</strong> <span class="badge bg-info"><?= htmlspecialchars($visit['tracking_number']) ?></span></p>
                            <?php endif; ?>
                            <p class="mb-0 text-muted">
                                <small>On <?= $visit['shipped_at'] ? date('d M Y, h:i A', strtotime($visit['shipped_at'])) : '—' ?></small>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Delivery Section -->
                    <?php if ($visit['delivered_at']): ?>
                    <div class="mb-3">
                        <div class="d-flex align-items-center mb-2">
                            <div class="bg-success text-white rounded-circle p-2 me-3">
                                <i class="bx bx-check-circle"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Delivered</h6>
                                <small class="text-muted">Items delivered to store</small>
                            </div>
                        </div>
                        <div class="ps-5">
                            <p class="mb-0 text-muted">
                                <small>Delivered on <?= date('d M Y, h:i A', strtotime($visit['delivered_at'])) ?></small>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Pending Status -->
                    <?php if (!$visit['approved_by_name'] && !$visit['packed_by_name'] && !$visit['shipped_by_name'] && !$visit['delivered_at']): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bx bx-time display-4 opacity-25"></i>
                        <h6>Awaiting Processing</h6>
                        <small>No actions have been taken on this visit yet.</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Visit Notes -->
    <?php if ($visit['visit_notes']): ?>
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Visit Notes</h5>
        </div>
        <div class="card-body">
            <div class="bg-light p-3 rounded">
                <?= nl2br(htmlspecialchars($visit['visit_notes'])) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Requirements Table -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                Stock Requirements 
                <span class="badge bg-primary"><?= $total_items ?> items</span>
            </h5>
            <div>
                <?php if ($role === 'seller' && $pending_count > 0): ?>
                <a href="store_requirements.php?approve_visit=<?= $id ?>" 
                   class="btn btn-success btn-sm"
                   onclick="return confirm('Approve all requirements for this visit?')">
                    <i class="bx bx-check"></i> Approve All
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if ($items): ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="40">#</th>
                            <th>Product</th>
                            <th>Code</th>
                            <th class="text-center">Qty</th>
                            <th class="text-center">Stock</th>
                            <th class="text-center">Urgency</th>
                            <th>Staff Actions</th>
                            <th class="text-center">Status</th>
                            <?php if ($role === 'admin' || $role === 'seller'): ?>
                            <th>Notes</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $item): 
                            $urgency_color = $item['urgency'] === 'high' ? 'danger' : 
                                           ($item['urgency'] === 'medium' ? 'warning' : 'secondary');
                            $status_color = [
                                'pending' => 'warning',
                                'approved' => 'info',
                                'packed' => 'primary',
                                'shipped' => 'dark',
                                'delivered' => 'success'
                            ][$item['requirement_status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td class="text-center"><?= $i + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($item['product_name']) ?></strong><br>
                                <small class="text-muted">₹<?= number_format($item['wholesale_price'], 2) ?> (wholesale)</small>
                            </td>
                            <td><small class="text-muted"><?= htmlspecialchars($item['product_code']) ?></small></td>
                            <td class="text-center fw-bold"><?= $item['required_quantity'] ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?= $item['warehouse_stock'] >= $item['required_quantity'] ? 'success' : 'danger' ?>">
                                    <?= $item['warehouse_stock'] ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $urgency_color ?> px-3">
                                    <?= ucfirst($item['urgency']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="small">
                                    <?php if ($item['approved_by_name']): ?>
                                    <div class="mb-1">
                                        <strong>Approved:</strong> <?= htmlspecialchars($item['approved_by_name']) ?><br>
                                        <small class="text-muted"><?= $item['approved_at'] ? date('d/m/y h:i A', strtotime($item['approved_at'])) : '' ?></small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['packed_by_name']): ?>
                                    <div class="mb-1">
                                        <strong>Packed:</strong> <?= htmlspecialchars($item['packed_by_name']) ?><br>
                                        <small class="text-muted"><?= $item['packed_at'] ? date('d/m/y h:i A', strtotime($item['packed_at'])) : '' ?></small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['shipped_by_name']): ?>
                                    <div>
                                        <strong>Shipped:</strong> <?= htmlspecialchars($item['shipped_by_name']) ?><br>
                                        <small class="text-muted"><?= $item['shipped_at'] ? date('d/m/y h:i A', strtotime($item['shipped_at'])) : '' ?></small>
                                        <?php if ($item['tracking_number']): ?>
                                        <br><small class="text-info">Tracking: <?= htmlspecialchars($item['tracking_number']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!$item['approved_by_name'] && !$item['packed_by_name'] && !$item['shipped_by_name']): ?>
                                    <span class="text-muted">No staff actions yet</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $status_color ?> px-3 py-2">
                                    <?= ucfirst($item['requirement_status']) ?>
                                </span>
                            </td>
                            <?php if ($role === 'admin' || $role === 'seller'): ?>
                            <td>
                                <small><?= $item['notes'] ? htmlspecialchars($item['notes']) : '<em class="text-muted">—</em>' ?></small>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="bx bx-package display-1 opacity-25"></i>
                <h6>No product requirements added</h6>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="text-end">
                <small class="text-muted">
                    Visit ID: #<?= $visit['id'] ?> • 
                    Submitted on <?= date('d M Y, h:i A', strtotime($visit['created_at'])) ?> • 
                  
                </small>
            </div>
        </div>
    </div>
</div>

<style>
.bg-light { background-color: #f8f9fa !important; }
.table-borderless th { font-weight: 600; color: #495057; }
.table-borderless td { padding: 6px 0; }
.rounded-circle { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; }
.table tbody tr:hover { background-color: rgba(0,123,255,0.05); }
</style>