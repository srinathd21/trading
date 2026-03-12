<?php
session_start();
require_once 'config/database.php';

// ==================== LOGIN & ROLE CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'];
$current_business_id = $_SESSION['current_business_id'] ?? null;
$current_shop_id = $_SESSION['current_shop_id'] ?? null;

// Only admin, shop_manager, and cashier can add expenses
$can_add_expense = in_array($user_role, ['admin', 'shop_manager', 'cashier']);
if (!$can_add_expense) {
    $_SESSION['error'] = "Access denied. You don't have permission to add expenses.";
    header('Location: dashboard.php');
    exit();
}

// Check if business_id is set
if (!$current_business_id) {
    $_SESSION['error'] = "Please select a business first.";
    header('Location: select_business.php');
    exit();
}

// ==================== GET SHOPS FOR CURRENT BUSINESS ====================
$all_shops = [];
if ($user_role === 'admin') {
    // Admin can see all active shops in the current business
    $stmt = $pdo->prepare("
        SELECT id, shop_name 
        FROM shops 
        WHERE business_id = ? AND is_active = 1 
        ORDER BY shop_name
    ");
    $stmt->execute([$current_business_id]);
    $all_shops = $stmt->fetchAll();
} elseif ($user_role !== 'admin' && $current_shop_id) {
    // Non-admin users can only see their assigned shop
    $stmt = $pdo->prepare("
        SELECT id, shop_name 
        FROM shops 
        WHERE id = ? AND business_id = ? AND is_active = 1
    ");
    $stmt->execute([$current_shop_id, $current_business_id]);
    $shop = $stmt->fetch();
    if ($shop) {
        $all_shops = [$shop]; // Only their assigned shop
    }
}

// ==================== HANDLE FORM SUBMISSION ====================
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_expense') {
        try {
            // Get shop_id from form
            if ($user_role === 'admin') {
                $shop_id = (int)$_POST['shop_id'];
                if ($shop_id <= 0) {
                    $error = "Please select a branch/shop!";
                }
            } else {
                $shop_id = $current_shop_id;
                if (!$shop_id) {
                    $error = "Please select a shop first!";
                    header('Location: select_shop.php');
                    exit();
                }
            }
            
            // Validate shop belongs to current business
            if (!$error) {
                $check_stmt = $pdo->prepare("
                    SELECT id FROM shops 
                    WHERE id = ? AND business_id = ? AND is_active = 1
                ");
                $check_stmt->execute([$shop_id, $current_business_id]);
                if (!$check_stmt->fetch()) {
                    $error = "Selected shop is not valid or does not belong to your business!";
                }
            }
            
            if (!$error) {
                $amount = floatval($_POST['amount']);
                $date = $_POST['date'];
                $category = trim($_POST['category']);
                $description = trim($_POST['description']);
                $reference = trim($_POST['reference'] ?? '');
                $payment_method = $_POST['payment_method'];
                $payment_reference = trim($_POST['payment_reference'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                
                // Validation
                if ($amount <= 0) {
                    $error = "Amount must be greater than 0!";
                } elseif (empty($category)) {
                    $error = "Category is required!";
                } elseif (empty($description)) {
                    $error = "Description is required!";
                } else {
                    // Insert expense with business_id
                    $stmt = $pdo->prepare("
                        INSERT INTO expenses (
                            shop_id, 
                            business_id,
                            amount, 
                            date, 
                            category, 
                            description, 
                            reference,
                            payment_method, 
                            payment_reference, 
                            added_by, 
                            status, 
                            notes, 
                            created_at, 
                            updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), NOW())
                    ");
                    
                    $stmt->execute([
                        $shop_id,
                        $current_business_id, // Add business_id
                        $amount,
                        $date,
                        $category,
                        $description,
                        $reference,
                        $payment_method,
                        $payment_reference,
                        $user_id,
                        $notes
                    ]);
                    
                    $message = "Expense added successfully!";
                    
                    // Clear form if not adding another
                    if (!isset($_POST['add_another']) || $_POST['add_another'] !== '1') {
                        header('Location: expenses.php?success=1');
                        exit();
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get shop name for display
$shop_name = 'Selected Branch/Shop';
if ($user_role !== 'admin' && $current_shop_id) {
    $shop_stmt = $pdo->prepare("
        SELECT shop_name 
        FROM shops 
        WHERE id = ? AND business_id = ?
    ");
    $shop_stmt->execute([$current_shop_id, $current_business_id]);
    $shop = $shop_stmt->fetch();
    $shop_name = $shop['shop_name'] ?? 'Selected Branch/Shop';
}

// Get business name for display
$business_stmt = $pdo->prepare("SELECT business_name FROM businesses WHERE id = ?");
$business_stmt->execute([$current_business_id]);
$business = $business_stmt->fetch();
$business_name = $business['business_name'] ?? 'Current Business';
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Add Expense"; ?>
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

                <!-- Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">Add New Expense</h4>
                                <p class="text-muted mb-0">Record business expenses for: <strong><?= htmlspecialchars($business_name) ?></strong></p>
                                <?php if ($user_role !== 'admin' && $shop_name): ?>
                                    <small class="text-info"><i class="bx bx-store-alt"></i> Branch: <?= htmlspecialchars($shop_name) ?></small>
                                <?php endif; ?>
                            </div>
                            <div>
                                <a href="expenses.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-2"></i>Back to Expenses
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i><?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i><?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Expense Form -->
                <div class="card">
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="action" value="add_expense">
                            <input type="hidden" name="business_id" value="<?= $current_business_id ?>">
                            
                            <?php if ($user_role === 'admin' && !empty($all_shops)): ?>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Select Branch/Shop *</label>
                                    <select class="form-select" name="shop_id" required>
                                        <option value="">Select Branch/Shop</option>
                                        <?php foreach ($all_shops as $shop): ?>
                                            <option value="<?= $shop['id'] ?>" <?= (isset($_POST['shop_id']) && $_POST['shop_id'] == $shop['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($shop['shop_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a branch/shop.</div>
                                    <small class="text-muted">Showing shops from <?= htmlspecialchars($business_name) ?></small>
                                </div>
                            </div>
                            <?php elseif ($user_role !== 'admin' && !empty($all_shops)): ?>
                                <input type="hidden" name="shop_id" value="<?= $current_shop_id ?>">
                                <div class="alert alert-info mb-3">
                                    <i class="bx bx-info-circle"></i> 
                                    Expense will be recorded for your assigned shop: <strong><?= htmlspecialchars($shop_name) ?></strong>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning mb-3">
                                    <i class="bx bx-error"></i> 
                                    No active shops found in your business. Please contact admin.
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($all_shops)): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <!-- Amount & Date -->
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Amount *</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" class="form-control" name="amount" 
                                                    step="0.01" min="0.01" required 
                                                    value="<?= isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : '' ?>">
                                                <div class="invalid-feedback">Please enter a valid amount.</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Date *</label>
                                            <input type="date" class="form-control" name="date" required
                                                value="<?= isset($_POST['date']) ? htmlspecialchars($_POST['date']) : date('Y-m-d') ?>">
                                            <div class="invalid-feedback">Please select a date.</div>
                                        </div>
                                    </div>

                                    <!-- Category -->
                                    <div class="mb-3">
                                        <label class="form-label">Category *</label>
                                        <select class="form-select" name="category" required>
                                            <option value="">Select Category</option>
                                            <option value="Rent" <?= (isset($_POST['category']) && $_POST['category'] === 'Rent') ? 'selected' : '' ?>>Rent</option>
                                            <option value="Salary" <?= (isset($_POST['category']) && $_POST['category'] === 'Salary') ? 'selected' : '' ?>>Salary</option>
                                            <option value="Utilities" <?= (isset($_POST['category']) && $_POST['category'] === 'Utilities') ? 'selected' : '' ?>>Utilities</option>
                                            <option value="Office Supplies" <?= (isset($_POST['category']) && $_POST['category'] === 'Office Supplies') ? 'selected' : '' ?>>Office Supplies</option>
                                            <option value="Transportation" <?= (isset($_POST['category']) && $_POST['category'] === 'Transportation') ? 'selected' : '' ?>>Transportation</option>
                                            <option value="Marketing" <?= (isset($_POST['category']) && $_POST['category'] === 'Marketing') ? 'selected' : '' ?>>Marketing</option>
                                            <option value="Maintenance" <?= (isset($_POST['category']) && $_POST['category'] === 'Maintenance') ? 'selected' : '' ?>>Maintenance</option>
                                            <option value="Other" <?= (isset($_POST['category']) && $_POST['category'] === 'Other') ? 'selected' : '' ?>>Other</option>
                                        </select>
                                        <div class="invalid-feedback">Please select a category.</div>
                                    </div>

                                    <!-- Description -->
                                    <div class="mb-3">
                                        <label class="form-label">Description *</label>
                                        <textarea class="form-control" name="description" rows="3" required
                                            placeholder="Describe the expense..."><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                                        <div class="invalid-feedback">Please enter a description.</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <!-- Payment Method -->
                                    <div class="mb-3">
                                        <label class="form-label">Payment Method *</label>
                                        <select class="form-select" name="payment_method" required>
                                            <option value="cash" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'cash') ? 'selected' : 'selected' ?>>Cash</option>
                                            <option value="bank_transfer" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'bank_transfer') ? 'selected' : '' ?>>Bank Transfer</option>
                                            <option value="credit_card" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'credit_card') ? 'selected' : '' ?>>Credit Card</option>
                                            <option value="cheque" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'cheque') ? 'selected' : '' ?>>Cheque</option>
                                            <option value="upi" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'upi') ? 'selected' : '' ?>>UPI</option>
                                            <option value="other" <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'other') ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>

                                    <!-- Payment Reference -->
                                    <div class="mb-3">
                                        <label class="form-label">Payment Reference</label>
                                        <input type="text" class="form-control" name="payment_reference"
                                            placeholder="e.g., UPI ID, Cheque No, Transaction ID"
                                            value="<?= isset($_POST['payment_reference']) ? htmlspecialchars($_POST['payment_reference']) : '' ?>">
                                    </div>

                                    <!-- Reference -->
                                    <div class="mb-3">
                                        <label class="form-label">Reference (Optional)</label>
                                        <input type="text" class="form-control" name="reference"
                                            placeholder="e.g., Invoice No, Receipt No"
                                            value="<?= isset($_POST['reference']) ? htmlspecialchars($_POST['reference']) : '' ?>">
                                    </div>

                                    <!-- Notes -->
                                    <div class="mb-3">
                                        <label class="form-label">Notes (Optional)</label>
                                        <textarea class="form-control" name="notes" rows="2"
                                            placeholder="Any additional notes..."><?= isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : '' ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="add_another" 
                                            id="addAnother" value="1" checked>
                                        <label class="form-check-label" for="addAnother">
                                            Add another expense after this
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6 text-end">
                                    <button type="reset" class="btn btn-secondary">Reset</button>
                                    <button type="submit" class="btn btn-primary">Save Expense</button>
                                </div>
                            </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <p class="text-muted">No shops available to add expenses.</p>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
// Form validation
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
});
</script>
</body>
</html>