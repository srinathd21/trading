<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['manufacturer_id'])) {
    echo '<div class="alert alert-danger">Invalid request</div>';
    exit();
}

$manufacturer_id = (int)$_POST['manufacturer_id'];
$business_id = $_SESSION['business_id'];

// Verify manufacturer belongs to this business
$stmt = $pdo->prepare("SELECT id, name FROM manufacturers WHERE id = ? AND business_id = ?");
$stmt->execute([$manufacturer_id, $business_id]);
$manufacturer = $stmt->fetch();
if (!$manufacturer) {
    echo '<div class="alert alert-danger">Manufacturer not found</div>';
    exit();
}

// Get outstanding history
$stmt = $pdo->prepare("
    SELECT h.*, u.username as created_by_name
    FROM manufacturer_outstanding_history h
    LEFT JOIN users u ON h.created_by = u.id
    WHERE h.manufacturer_id = ?
    ORDER BY h.date DESC, h.created_at DESC
");
$stmt->execute([$manufacturer_id]);
$history = $stmt->fetchAll();
?>

<div class="table-responsive">
    <table class="table table-sm table-bordered">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Balance After</th>
                <th>Reference</th>
                <th>Notes</th>
                <th>Created By</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($history)): ?>
            <tr>
                <td colspan="7" class="text-center py-3">
                    No outstanding history found
                </td>
            </tr>
            <?php else: ?>
                <?php 
                $running_balance = 0;
                foreach ($history as $row): 
                    $amount_class = ($row['amount'] > 0) ? 
                        (in_array($row['type'], ['credit', 'payment_received']) ? 'text-success' : 'text-danger') : '';
                    
                    $balance_class = $row['balance_after'] >= 0 ? 'text-success' : 'text-danger';
                    
                    $type_badge = match($row['type']) {
                        'credit' => '<span class="badge bg-success">Credit (Supplier owes you)</span>',
                        'debit' => '<span class="badge bg-danger">Debit (You owe supplier)</span>',
                        'payment_received' => '<span class="badge bg-info">Payment Received</span>',
                        'payment_made' => '<span class="badge bg-warning">Payment Made</span>',
                        'purchase' => '<span class="badge bg-primary">Purchase</span>',
                        'purchase_return' => '<span class="badge bg-secondary">Purchase Return</span>',
                        default => '<span class="badge bg-light">' . $row['type'] . '</span>'
                    };
                ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                    <td><?= $type_badge ?></td>
                    <td class="fw-bold <?= $amount_class ?>">
                        ₹<?= number_format($row['amount'], 2) ?>
                    </td>
                    <td class="fw-bold <?= $balance_class ?>">
                        ₹<?= number_format($row['balance_after'], 2) ?>
                    </td>
                    <td>
                        <?php if ($row['reference_no']): ?>
                        <small><?= htmlspecialchars($row['reference_no']) ?></small>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['notes']): ?>
                        <small><?= htmlspecialchars($row['notes']) ?></small>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><small><?= htmlspecialchars($row['created_by_name'] ?? 'System') ?></small></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>