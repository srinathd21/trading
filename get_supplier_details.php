<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['manufacturer_id'])) {
    echo '<div class="alert alert-danger">Invalid request</div>';
    exit();
}

$manufacturer_id = (int)$_POST['manufacturer_id'];
$business_id = $_SESSION['business_id'];

// Get manufacturer details with all related data
$stmt = $pdo->prepare("
    SELECT m.*, 
           s.shop_name,
           s.shop_code,
           (SELECT COUNT(*) FROM purchases p WHERE p.manufacturer_id = m.id) as total_purchases,
           (SELECT COALESCE(SUM(p.total_amount), 0) FROM purchases p WHERE p.manufacturer_id = m.id) as total_purchase_amount,
           (SELECT COALESCE(SUM(p.paid_amount), 0) FROM purchases p WHERE p.manufacturer_id = m.id) as total_paid_amount,
           (SELECT COALESCE(SUM(p.total_amount - p.paid_amount), 0) FROM purchases p WHERE p.manufacturer_id = m.id AND p.payment_status IN ('pending', 'partial')) as pending_purchase_amount
    FROM manufacturers m
    LEFT JOIN shops s ON m.shop_id = s.id
    WHERE m.id = ? AND m.business_id = ?
");
$stmt->execute([$manufacturer_id, $business_id]);
$manufacturer = $stmt->fetch();

if (!$manufacturer) {
    echo '<div class="alert alert-danger">Manufacturer not found</div>';
    exit();
}

// Get all contacts
$stmt = $pdo->prepare("
    SELECT * FROM manufacturer_contacts 
    WHERE manufacturer_id = ? 
    ORDER BY is_primary DESC, id ASC
");
$stmt->execute([$manufacturer_id]);
$contacts = $stmt->fetchAll();

// Get recent purchases
$stmt = $pdo->prepare("
    SELECT id, purchase_number, purchase_date, total_amount, paid_amount, 
           (total_amount - paid_amount) as balance_due, payment_status
    FROM purchases 
    WHERE manufacturer_id = ? 
    ORDER BY purchase_date DESC 
    LIMIT 5
");
$stmt->execute([$manufacturer_id]);
$recent_purchases = $stmt->fetchAll();

// Get recent payments
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name as recorded_by_name
    FROM payments p
    LEFT JOIN users u ON p.recorded_by = u.id
    WHERE p.type = 'supplier' AND p.reference_id IN (SELECT id FROM purchases WHERE manufacturer_id = ?)
    ORDER BY p.payment_date DESC, p.created_at DESC
    LIMIT 5
");
$stmt->execute([$manufacturer_id]);
$recent_payments = $stmt->fetchAll();
?>

<div class="row">
    <!-- Basic Information -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bx bx-info-circle me-2"></i>Basic Information</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr>
                        <th width="40%">Company Name:</th>
                        <td><strong><?= htmlspecialchars($manufacturer['name']) ?></strong></td>
                    </tr>
                    <?php if ($manufacturer['gstin']): ?>
                    <tr>
                        <th>GSTIN:</th>
                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($manufacturer['gstin']) ?></span></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Status:</th>
                        <td>
                            <?php if ($manufacturer['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($manufacturer['shop_name']): ?>
                    <tr>
                        <th>Assigned Shop:</th>
                        <td>
                            <span class="badge bg-info bg-opacity-10 text-info">
                                <?= htmlspecialchars($manufacturer['shop_name']) ?> (<?= $manufacturer['shop_code'] ?>)
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Created:</th>
                        <td><?= date('d M Y, h:i A', strtotime($manufacturer['created_at'])) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Contact Information -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bx bx-phone me-2"></i>Contact Information</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <?php if ($manufacturer['phone']): ?>
                    <tr>
                        <th width="40%">Main Phone:</th>
                        <td>
                            <a href="tel:<?= htmlspecialchars($manufacturer['phone']) ?>" class="text-decoration-none">
                                <i class="bx bx-phone me-1 text-primary"></i><?= htmlspecialchars($manufacturer['phone']) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($manufacturer['email']): ?>
                    <tr>
                        <th>Main Email:</th>
                        <td>
                            <a href="mailto:<?= htmlspecialchars($manufacturer['email']) ?>" class="text-decoration-none">
                                <i class="bx bx-envelope me-1 text-info"></i><?= htmlspecialchars($manufacturer['email']) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($manufacturer['address']): ?>
                    <tr>
                        <th>Address:</th>
                        <td>
                            <i class="bx bx-map me-1 text-muted"></i>
                            <?= nl2br(htmlspecialchars($manufacturer['address'])) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <?php if (!empty($contacts)): ?>
                <hr>
                <h6 class="mb-2">Contact Persons:</h6>
                <?php foreach ($contacts as $contact): ?>
                <div class="mb-2 p-2 <?= $contact['is_primary'] ? 'bg-light border-start border-primary' : '' ?>">
                    <div class="d-flex justify-content-between">
                        <strong><?= htmlspecialchars($contact['contact_person']) ?></strong>
                        <?php if ($contact['is_primary']): ?>
                        <span class="badge bg-primary">Primary</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($contact['designation']): ?>
                    <small class="text-muted d-block"><?= htmlspecialchars($contact['designation']) ?></small>
                    <?php endif; ?>
                    <?php if ($contact['phone'] || $contact['mobile']): ?>
                    <small class="d-block">
                        <i class="bx bx-phone me-1"></i>
                        <?= $contact['phone'] ?: $contact['mobile'] ?>
                    </small>
                    <?php endif; ?>
                    <?php if ($contact['email']): ?>
                    <small class="d-block">
                        <i class="bx bx-envelope me-1"></i>
                        <?= htmlspecialchars($contact['email']) ?>
                    </small>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bank & UPI Details -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bx bx-bank me-2"></i>Bank & UPI Details</h6>
            </div>
            <div class="card-body">
                <!-- Bank Details Section -->
                <?php if ($manufacturer['account_holder_name'] || $manufacturer['bank_name'] || $manufacturer['account_number']): ?>
                <table class="table table-sm table-borderless">
                    <?php if ($manufacturer['account_holder_name']): ?>
                    <tr>
                        <th width="40%">Account Holder:</th>
                        <td><?= htmlspecialchars($manufacturer['account_holder_name']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($manufacturer['bank_name']): ?>
                    <tr>
                        <th>Bank Name:</th>
                        <td><?= htmlspecialchars($manufacturer['bank_name']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($manufacturer['account_number']): ?>
                    <tr>
                        <th>Account Number:</th>
                        <td>
                            <span class="font-monospace">****<?= substr($manufacturer['account_number'], -4) ?></span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($manufacturer['ifsc_code']): ?>
                    <tr>
                        <th>IFSC Code:</th>
                        <td><span class="font-monospace"><?= htmlspecialchars($manufacturer['ifsc_code']) ?></span></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($manufacturer['branch_name']): ?>
                    <tr>
                        <th>Branch:</th>
                        <td><?= htmlspecialchars($manufacturer['branch_name']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php else: ?>
                <p class="text-muted mb-3">No bank details provided</p>
                <?php endif; ?>

                <!-- UPI Section -->
                <?php if ($manufacturer['upi_id'] || (!empty($manufacturer['upi_qr_code']) && file_exists($manufacturer['upi_qr_code']))): ?>
                <hr>
                <h6 class="mb-3"><i class="bx bx-mobile-alt me-1"></i>UPI Payment Details</h6>
                
                <?php if ($manufacturer['upi_id']): ?>
                <div class="mb-3">
                    <label class="form-label small text-muted">UPI ID:</label>
                    <div class="d-flex align-items-center">
                        <span class="badge bg-info bg-opacity-10 text-info p-2">
                            <i class="bx bx-at me-1"></i><?= htmlspecialchars($manufacturer['upi_id']) ?>
                        </span>
                        <button class="btn btn-sm btn-outline-secondary ms-2" 
                                onclick="copyToClipboard('<?= htmlspecialchars($manufacturer['upi_id']) ?>')"
                                title="Copy UPI ID">
                            <i class="bx bx-copy"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($manufacturer['upi_qr_code']) && file_exists($manufacturer['upi_qr_code'])): ?>
                <div class="mb-3">
                    <label class="form-label small text-muted">UPI QR Code:</label>
                    <div>
                        <img src="<?= htmlspecialchars($manufacturer['upi_qr_code']) ?>" 
                             alt="UPI QR Code" 
                             style="max-width: 150px; max-height: 150px;" 
                             class="img-thumbnail cursor-pointer"
                             onclick="showQRCode('<?= htmlspecialchars($manufacturer['upi_qr_code']) ?>', '<?= htmlspecialchars($manufacturer['name']) ?>')">
                        <div class="mt-2">
                            <button class="btn btn-sm btn-outline-primary" 
                                    onclick="showQRCode('<?= htmlspecialchars($manufacturer['upi_qr_code']) ?>', '<?= htmlspecialchars($manufacturer['name']) ?>')">
                                <i class="bx bx-qr me-1"></i> View Full Size
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Financial Summary -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bx bx-money me-2"></i>Financial Summary</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="bg-light p-3 rounded">
                            <small class="text-muted d-block">Total Purchases</small>
                            <h5 class="mb-0"><?= $manufacturer['total_purchases'] ?></h5>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-light p-3 rounded">
                            <small class="text-muted d-block">Purchase Amount</small>
                            <h5 class="mb-0 text-primary">₹<?= number_format($manufacturer['total_purchase_amount'], 2) ?></h5>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-light p-3 rounded">
                            <small class="text-muted d-block">Paid Amount</small>
                            <h5 class="mb-0 text-success">₹<?= number_format($manufacturer['total_paid_amount'], 2) ?></h5>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-light p-3 rounded">
                            <small class="text-muted d-block">Pending Payments</small>
                            <h5 class="mb-0 text-warning">₹<?= number_format($manufacturer['pending_purchase_amount'], 2) ?></h5>
                        </div>
                    </div>
                    <?php if ($manufacturer['initial_outstanding_amount'] > 0): ?>
                    <div class="col-12">
                        <div class="bg-<?= $manufacturer['initial_outstanding_type'] == 'credit' ? 'success' : 'danger' ?> bg-opacity-10 p-3 rounded">
                            <small class="text-muted d-block">Current Outstanding</small>
                            <h5 class="mb-0 text-<?= $manufacturer['initial_outstanding_type'] == 'credit' ? 'success' : 'danger' ?>">
                                <?= ucfirst($manufacturer['initial_outstanding_type']) ?>: 
                                ₹<?= number_format($manufacturer['initial_outstanding_amount'], 2) ?>
                            </h5>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Purchases -->
    <?php if (!empty($recent_purchases)): ?>
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bx bx-cart me-2"></i>Recent Purchases</h6>
                <a href="purchases.php?manufacturer=<?= $manufacturer_id ?>" class="btn btn-sm btn-primary">
                    View All
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>PO Number</th>
                                <th>Date</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Balance</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_purchases as $purchase): ?>
                            <tr>
                                <td>
                                    <a href="purchase_view.php?id=<?= $purchase['id'] ?>">
                                        <?= htmlspecialchars($purchase['purchase_number']) ?>
                                    </a>
                                </td>
                                <td><?= date('d M Y', strtotime($purchase['purchase_date'])) ?></td>
                                <td class="text-end">₹<?= number_format($purchase['total_amount'], 2) ?></td>
                                <td class="text-end text-success">₹<?= number_format($purchase['paid_amount'], 2) ?></td>
                                <td class="text-end text-warning">₹<?= number_format($purchase['balance_due'], 2) ?></td>
                                <td class="text-center">
                                    <?php 
                                    $status_color = [
                                        'paid' => 'success',
                                        'partial' => 'warning',
                                        'pending' => 'danger'
                                    ][$purchase['payment_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?>">
                                        <?= ucfirst($purchase['payment_status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent Payments -->
    <?php if (!empty($recent_payments)): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bx bx-history me-2"></i>Recent Payments</h6>
                <a href="purchase_payments_history.php?manufacturer=<?= $manufacturer_id ?>" class="btn btn-sm btn-primary">
                    View All
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_payments as $payment): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                <td class="text-success fw-bold">₹<?= number_format($payment['amount'], 2) ?></td>
                                <td>
                                    <span class="badge bg-<?= 
                                        ['cash' => 'info', 'bank' => 'primary', 'upi' => 'success', 'cheque' => 'warning'][$payment['payment_method']] ?? 'secondary'
                                    ?> bg-opacity-10 text-<?= 
                                        ['cash' => 'info', 'bank' => 'primary', 'upi' => 'success', 'cheque' => 'warning'][$payment['payment_method']] ?? 'secondary'
                                    ?>">
                                        <?= ucfirst($payment['payment_method']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($payment['reference_no'] ?: '-') ?></td>
                                <td><small><?= htmlspecialchars($payment['recorded_by_name'] ?: 'System') ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add this JavaScript for copy functionality and QR code viewing -->
<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        Swal.fire({
            icon: 'success',
            title: 'Copied!',
            text: 'UPI ID copied to clipboard',
            timer: 1500,
            showConfirmButton: false
        });
    }, function(err) {
        console.error('Could not copy text: ', err);
    });
}

function showQRCode(qrPath, supplierName) {
    // Check if QR code modal exists, if not create it
    if ($('#qrCodeModal').length === 0) {
        $('body').append(`
            <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-sm">
                    <div class="modal-content">
                        <div class="modal-header bg-info text-white">
                            <h5 class="modal-title">
                                <i class="bx bx-qr me-2"></i>
                                UPI QR Code: <span id="qrSupplierName"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-center p-4">
                            <img id="qrCodeImage" src="" alt="UPI QR Code" style="max-width: 250px;" class="img-fluid border rounded">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }
    
    $('#qrSupplierName').text(supplierName);
    $('#qrCodeImage').attr('src', qrPath);
    
    var qrModal = new bootstrap.Modal(document.getElementById('qrCodeModal'));
    qrModal.show();
}

// Add cursor pointer style for QR code images
$('head').append('<style>.cursor-pointer { cursor: pointer; }</style>');
</script>