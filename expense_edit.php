<?php
session_start();
require_once 'config/database.php';

// ==================== LOGIN & ROLE CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$user_id   = $_SESSION['user_id'];

// Only admin, shop_manager can edit expenses
$can_edit = in_array($user_role, ['admin', 'shop_manager']);
if (!$can_edit) {
    $_SESSION['error'] = "Access denied. You don't have permission to edit expenses.";
    header('Location: expenses.php');
    exit();
}

// ==================== GET EXPENSE ID ====================
$expense_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($expense_id <= 0) {
    $_SESSION['error'] = "Invalid expense ID.";
    header('Location: expenses.php');
    exit();
}

// ==================== FETCH EXPENSE ====================
$stmt = $pdo->prepare("
    SELECT e.*, s.shop_name 
    FROM expenses e 
    LEFT JOIN shops s ON e.shop_id = s.id 
    WHERE e.id = ?
");
$stmt->execute([$expense_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    $_SESSION['error'] = "Expense not found.";
    header('Location: expenses.php');
    exit();
}

// Non-admin users can only edit expenses from their own shop
if ($user_role !== 'admin' && $expense['shop_id'] != ($_SESSION['current_shop_id'] ?? 0)) {
    $_SESSION['error'] = "You can only edit expenses from your assigned shop.";
    header('Location: expenses.php');
    exit();
}

$shop_name = $expense['shop_name'] ?? 'Unknown Shop';

// ==================== HANDLE FORM SUBMISSION ====================
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_expense') {
        try {
            $amount            = floatval($_POST['amount']);
            $date              = $_POST['date'];
            $category          = trim($_POST['category']);
            $description       = trim($_POST['description']);
            $reference         = trim($_POST['reference'] ?? '');
            $payment_method    = $_POST['payment_method'];
            $payment_reference = trim($_POST['payment_reference'] ?? '');
            $notes             = trim($_POST['notes'] ?? '');
            $status            = $_POST['status']; // Allow changing status

            // Validation
            if ($amount <= 0) {
                $error = "Amount must be greater than 0!";
            } elseif (empty($category)) {
                $error = "Category is required!";
            } elseif (empty($description)) {
                $error = "Description is required!";
            } else {
                // Update expense
                $stmt = $pdo->prepare("
                    UPDATE expenses SET
                        amount = ?, date = ?, category = ?, description = ?, reference = ?,
                        payment_method = ?, payment_reference = ?, notes = ?, status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                $stmt->execute([
                    $amount,
                    $date,
                    $category,
                    $description,
                    $reference,
                    $payment_method,
                    $payment_reference,
                    $notes,
                    $status,
                    $expense_id
                ]);

                $message = "Expense updated successfully!";

                // Refresh expense data
                $stmt = $pdo->prepare("
                    SELECT e.*, s.shop_name 
                    FROM expenses e 
                    LEFT JOIN shops s ON e.shop_id = s.id 
                    WHERE e.id = ?
                ");
                $stmt->execute([$expense_id]);
                $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!doctype html>
<html lang="en">
<?php $page_title = "Edit Expense - " . htmlspecialchars($expense['description']); ?>
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
                                <h4 class="mb-1">Edit Expense</h4>
                                <p class="text-muted mb-0">
                                    Updating expense for <strong><?= htmlspecialchars($shop_name) ?></strong>
                                </p>
                                <small class="text-muted">
                                    Expense ID: #<?= $expense['id'] ?> | Added on <?= date('d/m/Y h:i A', strtotime($expense['created_at'])) ?>
                                </small>
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

                <!-- Edit Form -->
                <div class="card">
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="action" value="update_expense">

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Amount *</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required
                                                       value="<?= htmlspecialchars($expense['amount']) ?>">
                                                <div class="invalid-feedback">Please enter a valid amount.</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Date *</label>
                                            <input type="date" class="form-control" name="date" required
                                                   value="<?= $expense['date'] ?>">
                                            <div class="invalid-feedback">Please select a date.</div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Category *</label>
                                        <select class="form-select" name="category" required>
                                            <option value="">Select Category</option>
                                            <?php
                                            $categories = ['Rent', 'Salary', 'Utilities', 'Office Supplies', 'Transportation', 'Marketing', 'Maintenance', 'Other'];
                                            foreach ($categories as $cat): ?>
                                                <option value="<?= $cat ?>" <?= ($expense['category'] === $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description *</label>
                                        <textarea class="form-control" name="description" rows="4" required
                                                  placeholder="Describe the expense..."><?= htmlspecialchars($expense['description']) ?></textarea>
                                        <div class="invalid-feedback">Please enter a description.</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Payment Method *</label>
                                        <select class="form-select" name="payment_method" required>
                                            <?php
                                            $methods = ['cash', 'bank_transfer', 'credit_card', 'cheque', 'upi', 'other'];
                                            foreach ($methods as $method):
                                                $label = ucfirst(str_replace('_', ' ', $method));
                                                $selected = ($expense['payment_method'] === $method) ? 'selected' : '';
                                            ?>
                                                <option value="<?= $method ?>" <?= $selected ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Payment Reference</label>
                                        <input type="text" class="form-control" name="payment_reference"
                                               placeholder="e.g., UPI ID, Cheque No, Transaction ID"
                                               value="<?= htmlspecialchars($expense['payment_reference'] ?? '') ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Reference (Optional)</label>
                                        <input type="text" class="form-control" name="reference"
                                               placeholder="e.g., Invoice No, Receipt No"
                                               value="<?= htmlspecialchars($expense['reference'] ?? '') ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Notes (Optional)</label>
                                        <textarea class="form-control" name="notes" rows="3"
                                                  placeholder="Any additional notes..."><?= htmlspecialchars($expense['notes'] ?? '') ?></textarea>
                                    </div>

                                    <?php if ($user_role === 'admin' || $user_role === 'shop_manager'): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status">
                                            <?php foreach (['pending','approved','rejected','reconciled'] as $st): ?>
                                                <option value="<?= $st ?>" <?= ($expense['status'] === $st) ? 'selected' : '' ?>>
                                                    <?= ucfirst($st) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-4 text-end">
                                <a href="expenses.php" class="btn btn-secondary me-2">Cancel</a>
                                <button type="submit" class="btn btn-primary">Update Expense</button>
                            </div>
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
// Bootstrap form validation
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