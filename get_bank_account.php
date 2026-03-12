<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$account_id = $_GET['account_id'] ?? 0;
$business_id = $_SESSION['business_id'] ?? 1;

$sql = "SELECT * FROM bank_accounts WHERE id = ? AND business_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$account_id, $business_id]);
$account = $stmt->fetch();

if (!$account) {
    echo '<div class="alert alert-danger">Account not found.</div>';
    exit;
}
?>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Bank Name *</label>
        <input type="text" class="form-control" name="bank_name" 
               value="<?php echo htmlspecialchars($account['bank_name']); ?>" required>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Account Number *</label>
        <input type="text" class="form-control" name="account_number" 
               value="<?php echo htmlspecialchars($account['account_number']); ?>" required>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Account Holder Name</label>
        <input type="text" class="form-control" name="account_holder_name"
               value="<?php echo htmlspecialchars($account['account_holder_name'] ?? ''); ?>">
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">IFSC Code</label>
        <input type="text" class="form-control" name="ifsc_code"
               value="<?php echo htmlspecialchars($account['ifsc_code'] ?? ''); ?>">
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Branch Name</label>
        <input type="text" class="form-control" name="branch_name"
               value="<?php echo htmlspecialchars($account['branch_name'] ?? ''); ?>">
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Account Type</label>
        <select class="form-control" name="account_type">
            <option value="">Select Type</option>
            <option value="Savings" <?php echo ($account['account_type'] ?? '') == 'Savings' ? 'selected' : ''; ?>>Savings Account</option>
            <option value="Current" <?php echo ($account['account_type'] ?? '') == 'Current' ? 'selected' : ''; ?>>Current Account</option>
            <option value="Salary" <?php echo ($account['account_type'] ?? '') == 'Salary' ? 'selected' : ''; ?>>Salary Account</option>
            <option value="Fixed Deposit" <?php echo ($account['account_type'] ?? '') == 'Fixed Deposit' ? 'selected' : ''; ?>>Fixed Deposit</option>
        </select>
    </div>
    <div class="col-md-12 mb-3">
        <label class="form-label">UPI ID</label>
        <input type="text" class="form-control" name="upi_id" 
               value="<?php echo htmlspecialchars($account['upi_id'] ?? ''); ?>" placeholder="username@upi">
    </div>
    <div class="col-md-6 mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" 
                   <?php echo $account['is_active'] ? 'checked' : ''; ?>>
            <label class="form-check-label" for="edit_is_active">
                Active (Will appear on invoices)
            </label>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_default" id="edit_is_default"
                   <?php echo $account['is_default'] ? 'checked' : ''; ?>>
            <label class="form-check-label" for="edit_is_default">
                Set as Default Account
            </label>
        </div>
    </div>
</div>