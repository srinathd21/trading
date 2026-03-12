<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_SESSION['current_business_id'])) {
    header('Location: select_shop.php');
    exit();
}

$current_business_id = (int) $_SESSION['current_business_id'];
$referral_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($referral_id <= 0) {
    header('Location: referral_persons.php');
    exit();
}

// Fetch referral details
$stmt = $pdo->prepare("SELECT * FROM referral_person WHERE id = ? AND business_id = ?");
$stmt->execute([$referral_id, $current_business_id]);
$referral = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$referral) {
    header('Location: referral_persons.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $proof_id_type = $_POST['proof_id_type'] ?? null;
    $proof_id_number = trim($_POST['proof_id_number'] ?? '');
    $commission_percent = (float)$_POST['commission_percent'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    $initial_outstanding_type = $_POST['initial_outstanding_type'] ?? null;
    $initial_outstanding_amount = (float)$_POST['initial_outstanding_amount'] ?? 0.00;
    
    // Validate
    $errors = [];
    
    if (empty($full_name)) {
        $errors[] = 'Full name is required';
    }
    
    if (!empty($phone)) {
        // Check if phone already exists for another referral in same business
        $check_stmt = $pdo->prepare("SELECT id FROM referral_person WHERE phone = ? AND business_id = ? AND id != ?");
        $check_stmt->execute([$phone, $current_business_id, $referral_id]);
        if ($check_stmt->fetch()) {
            $errors[] = 'Phone number already exists for another referral';
        }
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    if ($commission_percent < 0 || $commission_percent > 100) {
        $errors[] = 'Commission percentage must be between 0 and 100';
    }
    
    // Validate outstanding amount
    if ($initial_outstanding_amount < 0) {
        $errors[] = 'Initial outstanding amount cannot be negative';
    }
    
    if ($initial_outstanding_amount > 0 && empty($initial_outstanding_type)) {
        $errors[] = 'Please select outstanding type when amount is greater than 0';
    }
    
    if (!empty($initial_outstanding_type) && $initial_outstanding_amount == 0) {
        $errors[] = 'Outstanding amount must be greater than 0 when type is selected';
    }

    // Handle file upload
    $uploaded_file_path = $referral['proof_id_file'] ?? '';
    if (!empty($_FILES['proof_id_file']['name'])) {
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        $max_file_size = 5 * 1024 * 1024; // 5MB
        $file_name = $_FILES['proof_id_file']['name'];
        $file_tmp = $_FILES['proof_id_file']['tmp_name'];
        $file_size = $_FILES['proof_id_file']['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Validate file extension
        if (!in_array($file_ext, $allowed_extensions)) {
            $errors[] = "Only PDF, JPG, JPEG, PNG, GIF files are allowed";
        }
        
        // Validate file size
        if ($file_size > $max_file_size) {
            $errors[] = "File size must be less than 5MB";
        }
        
        if (empty($errors)) {
            // Create upload directory if it doesn't exist
            $upload_dir = '../uploads/referral_proofs/' . $current_business_id . '/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $new_filename = 'proof_' . time() . '_' . uniqid() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;
            
            // Move uploaded file
            if (move_uploaded_file($file_tmp, $upload_path)) {
                // Delete old file if exists
                if (!empty($referral['proof_id_file']) && file_exists('../' . $referral['proof_id_file'])) {
                    unlink('../' . $referral['proof_id_file']);
                }
                $uploaded_file_path = 'uploads/referral_proofs/' . $current_business_id . '/' . $new_filename;
            } else {
                $errors[] = "Failed to upload file. Please try again.";
            }
        }
    } elseif (isset($_POST['remove_proof_file']) && $_POST['remove_proof_file'] == '1') {
        // Remove existing file
        if (!empty($referral['proof_id_file']) && file_exists('../' . $referral['proof_id_file'])) {
            unlink('../' . $referral['proof_id_file']);
        }
        $uploaded_file_path = '';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Calculate updated balance if initial outstanding changed
            $new_debit_amount = $referral['debit_amount'];
            $new_paid_amount = $referral['paid_amount'];
            $new_balance_due = $referral['balance_due'];
            
            // Only update if initial outstanding changed
            if ($initial_outstanding_type != $referral['initial_outstanding_type'] || 
                $initial_outstanding_amount != $referral['initial_outstanding_amount']) {
                
                // Remove previous initial outstanding from calculations
                if ($referral['initial_outstanding_type'] === 'debit') {
                    $new_debit_amount -= $referral['initial_outstanding_amount'];
                    $new_balance_due -= $referral['initial_outstanding_amount'];
                } elseif ($referral['initial_outstanding_type'] === 'credit') {
                    $new_paid_amount -= $referral['initial_outstanding_amount'];
                    $new_balance_due += $referral['initial_outstanding_amount'];
                }
                
                // Add new initial outstanding to calculations
                if ($initial_outstanding_type === 'debit') {
                    $new_debit_amount += $initial_outstanding_amount;
                    $new_balance_due += $initial_outstanding_amount;
                } elseif ($initial_outstanding_type === 'credit') {
                    $new_paid_amount += $initial_outstanding_amount;
                    $new_balance_due -= $initial_outstanding_amount;
                }
            }
            
            $update_stmt = $pdo->prepare("
                UPDATE referral_person 
                SET full_name = ?,
                    phone = ?,
                    email = ?,
                    address = ?,
                    department = ?,
                    proof_id_type = ?,
                    proof_id_number = ?,
                    proof_id_file = ?,
                    commission_percent = ?,
                    initial_outstanding_type = ?,
                    initial_outstanding_amount = ?,
                    debit_amount = ?,
                    paid_amount = ?,
                    balance_due = ?,
                    is_active = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND business_id = ?
            ");
            
            $update_stmt->execute([
                $full_name,
                $phone,
                $email,
                $address,
                $department,
                $proof_id_type,
                $proof_id_number,
                $uploaded_file_path,
                $commission_percent,
                $initial_outstanding_type,
                $initial_outstanding_amount,
                $new_debit_amount,
                $new_paid_amount,
                $new_balance_due,
                $is_active,
                $notes,
                $referral_id,
                $current_business_id
            ]);
            
            $pdo->commit();
            $success = 'Referral person updated successfully!';
            
            // Refresh referral data
            $stmt->execute([$referral_id, $current_business_id]);
            $referral = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to update referral: ' . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}
?>
<!doctype html>
<html lang="en">
<?php 
$page_title = "Edit Referral - " . htmlspecialchars($referral['full_name']); 
include(__DIR__ . '/includes/head.php'); 
?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include(__DIR__ . '/includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include(__DIR__ . '/includes/sidebar.php'); ?>
        </div>
    </div>
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bx bx-edit me-2"></i> Edit Referral Person
                                </h4>
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb mb-0">
                                        <li class="breadcrumb-item"><a href="referrals.php">Referral Persons</a></li>
                                        <li class="breadcrumb-item"><a href="referral_view.php?id=<?= $referral_id ?>"><?= htmlspecialchars($referral['full_name']) ?></a></li>
                                        <li class="breadcrumb-item active">Edit</li>
                                    </ol>
                                </nav>
                            </div>
                            <div>
                                <a href="referral_view.php?id=<?= $referral_id ?>" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to View
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="bx bx-error-circle me-2"></i><?= $error ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>

                                <form method="POST" action="" enctype="multipart/form-data">
                                    <!-- Basic Information -->
                                    <div class="card border mb-4">
                                        <div class="card-header bg-light">
                                            <h5 class="card-title mb-0">
                                                <i class="bx bx-user me-2"></i> Basic Information
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="full_name" 
                                                           value="<?= htmlspecialchars($referral['full_name']) ?>" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Referral Code</label>
                                                    <input type="text" class="form-control" 
                                                           value="<?= htmlspecialchars($referral['referral_code']) ?>" readonly>
                                                    <small class="text-muted">Referral code cannot be changed</small>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Phone</label>
                                                    <input type="text" class="form-control" name="phone" 
                                                           value="<?= htmlspecialchars($referral['phone'] ?? '') ?>">
                                                    <small class="text-muted">10-digit mobile number</small>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Email</label>
                                                    <input type="email" class="form-control" name="email" 
                                                           value="<?= htmlspecialchars($referral['email'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Department</label>
                                                    <input type="text" class="form-control" name="department" 
                                                           value="<?= htmlspecialchars($referral['department'] ?? '') ?>"
                                                           placeholder="e.g., Sales, Marketing, Operations">
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Address</label>
                                                <textarea class="form-control" name="address" rows="3"><?= htmlspecialchars($referral['address'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Initial Outstanding Balance -->
                                    <div class="card border mb-4">
                                        <div class="card-header bg-light">
                                            <h5 class="card-title mb-0">
                                                <i class="bx bx-credit-card me-2"></i> Initial Outstanding Balance
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Initial Outstanding Type</label>
                                                    <select name="initial_outstanding_type" class="form-select" id="outstandingType">
                                                        <option value="">No Outstanding</option>
                                                        <option value="debit" <?= ($referral['initial_outstanding_type'] ?? '') === 'debit' ? 'selected' : '' ?>>
                                                            Debit (You owe referral person)
                                                        </option>
                                                        <option value="credit" <?= ($referral['initial_outstanding_type'] ?? '') === 'credit' ? 'selected' : '' ?>>
                                                            Credit (Referral person owes you)
                                                        </option>
                                                    </select>
                                                    <small class="text-muted">
                                                        Select if there's any existing outstanding balance
                                                    </small>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Initial Outstanding Amount (₹)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">₹</span>
                                                        <input type="number" name="initial_outstanding_amount" 
                                                               class="form-control" 
                                                               value="<?= htmlspecialchars($referral['initial_outstanding_amount'] ?? '0') ?>"
                                                               step="0.01" min="0" 
                                                               id="outstandingAmount">
                                                        <span class="input-group-text">.00</span>
                                                    </div>
                                                    <small class="text-muted" id="outstandingDescription">
                                                        Enter outstanding amount
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="alert alert-info" id="outstandingInfo">
                                                <i class="bx bx-info-circle me-2"></i>
                                                <small>
                                                    <strong>Debit:</strong> You owe money to referral person (Business liability)<br>
                                                    <strong>Credit:</strong> Referral person owes money to you (Business asset)
                                                </small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Commission Settings -->
                                    <div class="card border mb-4">
                                        <div class="card-header bg-light">
                                            <h5 class="card-title mb-0">
                                                <i class="bx bx-percent me-2"></i> Commission Settings
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Commission Percentage <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <input type="number" class="form-control" name="commission_percent" 
                                                               value="<?= $referral['commission_percent'] ?>" 
                                                               step="0.01" min="0" max="100" required>
                                                        <span class="input-group-text">%</span>
                                                    </div>
                                                    <small class="text-muted">Percentage commission on referred sales</small>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Status</label>
                                                    <div class="form-check form-switch">
                                                        <input type="checkbox" class="form-check-input" name="is_active" 
                                                               id="is_active" <?= $referral['is_active'] ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="is_active">
                                                            Active
                                                        </label>
                                                    </div>
                                                    <small class="text-muted">Inactive referrals won't receive new commissions</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ID Proof Information -->
                                    <div class="card border mb-4">
                                        <div class="card-header bg-light">
                                            <h5 class="card-title mb-0">
                                                <i class="bx bx-id-card me-2"></i> ID Proof Information
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Proof of ID Type</label>
                                                    <select name="proof_id_type" class="form-select">
                                                        <option value="">Select ID Type</option>
                                                        <option value="aadhar" <?= ($referral['proof_id_type'] ?? '') === 'aadhar' ? 'selected' : '' ?>>Aadhar Card</option>
                                                        <option value="pan" <?= ($referral['proof_id_type'] ?? '') === 'pan' ? 'selected' : '' ?>>PAN Card</option>
                                                        <option value="voter_id" <?= ($referral['proof_id_type'] ?? '') === 'voter_id' ? 'selected' : '' ?>>Voter ID</option>
                                                        <option value="driving_license" <?= ($referral['proof_id_type'] ?? '') === 'driving_license' ? 'selected' : '' ?>>Driving License</option>
                                                        <option value="passport" <?= ($referral['proof_id_type'] ?? '') === 'passport' ? 'selected' : '' ?>>Passport</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Proof of ID Number</label>
                                                    <input type="text" name="proof_id_number" class="form-control" 
                                                           value="<?= htmlspecialchars($referral['proof_id_number'] ?? '') ?>"
                                                           placeholder="Enter ID number">
                                                </div>
                                                <div class="col-md-12 mb-3">
                                                    <label class="form-label">Upload ID Proof (Optional)</label>
                                                    <div class="input-group">
                                                        <input type="file" name="proof_id_file" class="form-control" 
                                                               accept=".pdf,.jpg,.jpeg,.png,.gif">
                                                    </div>
                                                    <small class="text-muted">
                                                        Allowed: PDF, JPG, PNG, GIF (Max: 5MB)
                                                    </small>
                                                    <?php if (!empty($referral['proof_id_file'])): ?>
                                                    <div class="mt-2">
                                                        <div class="d-flex align-items-center">
                                                            <i class="bx bx-file text-primary me-2"></i>
                                                            <small>Current file: <?= basename($referral['proof_id_file']) ?></small>
                                                        </div>
                                                        <div class="form-check mt-1">
                                                            <input class="form-check-input" type="checkbox" name="remove_proof_file" value="1" id="removeProofFile">
                                                            <label class="form-check-label" for="removeProofFile">
                                                                Remove current file
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div class="card border mb-4">
                                        <div class="card-header bg-light">
                                            <h5 class="card-title mb-0">
                                                <i class="bx bx-note me-2"></i> Additional Information
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Notes</label>
                                                <textarea class="form-control" name="notes" rows="4"><?= htmlspecialchars($referral['notes'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between">
                                        <a href="referral_view.php?id=<?= $referral_id ?>" class="btn btn-secondary">
                                            <i class="bx bx-x me-1"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bx bx-save me-1"></i> Update Referral
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Stats Card -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Current Statistics</h5>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Balance Due</span>
                                            <strong class="<?= $referral['balance_due'] > 0 ? 'text-danger' : ($referral['balance_due'] < 0 ? 'text-success' : 'text-secondary') ?>">
                                                ₹<?= number_format(abs($referral['balance_due']), 2) ?>
                                            </strong>
                                        </div>
                                        <small class="text-muted d-block">
                                            <?php if ($referral['balance_due'] > 0): ?>
                                            <i class="bx bx-down-arrow-alt text-danger me-1"></i> You owe money
                                            <?php elseif ($referral['balance_due'] < 0): ?>
                                            <i class="bx bx-up-arrow-alt text-success me-1"></i> They owe money
                                            <?php else: ?>
                                            <i class="bx bx-check text-secondary me-1"></i> Settled
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Initial Outstanding</span>
                                            <strong class="<?= $referral['initial_outstanding_type'] === 'debit' ? 'text-danger' : ($referral['initial_outstanding_type'] === 'credit' ? 'text-success' : 'text-secondary') ?>">
                                                ₹<?= number_format($referral['initial_outstanding_amount'], 2) ?>
                                            </strong>
                                        </div>
                                        <small class="text-muted d-block">
                                            <?php if ($referral['initial_outstanding_type'] === 'debit'): ?>
                                            <i class="bx bx-down-arrow-alt text-danger me-1"></i> Initial Debit
                                            <?php elseif ($referral['initial_outstanding_type'] === 'credit'): ?>
                                            <i class="bx bx-up-arrow-alt text-success me-1"></i> Initial Credit
                                            <?php else: ?>
                                            No initial outstanding
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Total Earned</span>
                                            <strong class="text-primary">₹<?= number_format($referral['debit_amount'], 2) ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Total Paid</span>
                                            <strong class="text-info">₹<?= number_format($referral['paid_amount'], 2) ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Total Referrals</span>
                                            <strong><?= $referral['total_referrals'] ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Total Sales</span>
                                            <strong class="text-warning">₹<?= number_format($referral['total_sales_amount'], 2) ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Quick Actions</h5>
                                <div class="d-grid gap-2">
                                    <a href="pay_referral.php?id=<?= $referral_id ?>" class="btn btn-success">
                                        <i class="bx bx-money me-1"></i> Make Payment
                                    </a>
                                    <a href="referral_transactions.php?referral_id=<?= $referral_id ?>" class="btn btn-outline-primary">
                                        <i class="bx bx-transfer me-1"></i> View Transactions
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Outstanding Summary -->
                        <div class="card shadow-sm" id="outstandingSummary" style="display: none;">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="bx bx-credit-card me-2"></i> Outstanding Preview
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-3">
                                    <h3 id="summaryAmount" class="mb-1">₹0.00</h3>
                                    <small class="text-muted" id="summaryType">No Outstanding</small>
                                </div>
                                <div class="border-top pt-3">
                                    <small class="text-muted">
                                        <strong>Initial Balance:</strong> <span id="summaryInitial">₹0.00</span><br>
                                        <strong>Type:</strong> <span id="summaryTypeDetail">-</span><br>
                                        <strong>Status:</strong> <span id="summaryStatus" class="badge bg-secondary">No Balance</span>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include(__DIR__ . '/includes/footer.php'); ?>
    </div>
</div>
<?php include(__DIR__ . '/includes/rightbar.php'); ?>
<?php include(__DIR__ . '/includes/scripts.php'); ?>

<script>
$(document).ready(function() {
    // Update outstanding summary
    function updateOutstandingSummary() {
        const type = $('#outstandingType').val();
        const amount = parseFloat($('#outstandingAmount').val()) || 0;
        
        if (type && amount > 0) {
            $('#outstandingSummary').show();
            $('#summaryAmount').text('₹' + amount.toFixed(2));
            $('#summaryInitial').text('₹' + amount.toFixed(2));
            $('#summaryType').text(type === 'debit' ? 'You owe referral person' : 'Referral person owes you');
            $('#summaryTypeDetail').text(type === 'debit' ? 'Debit Outstanding' : 'Credit Outstanding');
            
            if (type === 'debit') {
                $('#summaryStatus').removeClass().addClass('badge bg-danger').text('Business Liability');
                $('#summaryAmount').addClass('text-danger');
                $('#outstandingDescription').html('<span class="text-danger"><i class="bx bx-down-arrow-alt"></i> You need to pay this amount</span>');
            } else {
                $('#summaryStatus').removeClass().addClass('badge bg-success').text('Business Asset');
                $('#summaryAmount').addClass('text-success');
                $('#outstandingDescription').html('<span class="text-success"><i class="bx bx-up-arrow-alt"></i> You will receive this amount</span>');
            }
        } else {
            $('#outstandingSummary').hide();
            $('#summaryAmount').removeClass('text-danger text-success');
            $('#outstandingDescription').text('Enter outstanding amount');
        }
    }

    // Outstanding type and amount change handlers
    $('#outstandingType, #outstandingAmount').on('change input', function() {
        updateOutstandingSummary();
        
        // Enable/disable amount input based on type
        const type = $('#outstandingType').val();
        if (type) {
            $('#outstandingAmount').prop('disabled', false).focus();
        } else {
            $('#outstandingAmount').prop('disabled', true).val(0);
        }
    });

    // Initial update
    updateOutstandingSummary();
    
    // Enable/disable amount field based on initial type
    const initialType = $('#outstandingType').val();
    if (!initialType) {
        $('#outstandingAmount').prop('disabled', true);
    }

    // Auto-hide alerts
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Phone number formatting
    $('input[name="phone"]').on('input', function() {
        let value = $(this).val().replace(/\D/g, '');
        if (value.length > 10) {
            value = value.substr(0, 10);
        }
        $(this).val(value);
    });

    // ID number formatting based on type
    $('select[name="proof_id_type"]').on('change', function() {
        const idType = $(this).val();
        const idNumberInput = $('input[name="proof_id_number"]');
        
        // Clear previous placeholder and pattern
        idNumberInput.attr('placeholder', 'Enter ID number');
        idNumberInput.attr('pattern', '');
        
        if (idType === 'aadhar') {
            idNumberInput.attr('placeholder', 'XXXX XXXX XXXX (12 digits)');
            idNumberInput.attr('pattern', '^[2-9]{1}[0-9]{3}\\s[0-9]{4}\\s[0-9]{4}$');
            idNumberInput.attr('title', 'Aadhar format: 1234 5678 9012');
        } else if (idType === 'pan') {
            idNumberInput.attr('placeholder', 'ABCDE1234F (10 characters)');
            idNumberInput.attr('pattern', '^[A-Z]{5}[0-9]{4}[A-Z]{1}$');
            idNumberInput.attr('title', 'PAN format: ABCDE1234F');
        } else if (idType === 'voter_id') {
            idNumberInput.attr('placeholder', 'Voter ID number');
        } else if (idType === 'driving_license') {
            idNumberInput.attr('placeholder', 'DL-XXXXXXX-XXXXX');
        } else if (idType === 'passport') {
            idNumberInput.attr('placeholder', 'A1234567 (8-9 characters)');
            idNumberInput.attr('pattern', '^[A-Z]{1}[0-9]{7}$');
            idNumberInput.attr('title', 'Passport format: A1234567');
        }
    });

    // Auto-format Aadhar number
    $('input[name="proof_id_number"]').on('input', function() {
        const idType = $('select[name="proof_id_type"]').val();
        let value = $(this).val().replace(/\s/g, '').toUpperCase();
        
        if (idType === 'aadhar' && value.length <= 12) {
            // Format as XXXX XXXX XXXX
            if (value.length > 4 && value.length <= 8) {
                value = value.substr(0, 4) + ' ' + value.substr(4);
            } else if (value.length > 8) {
                value = value.substr(0, 4) + ' ' + value.substr(4, 4) + ' ' + value.substr(8);
            }
        }
        
        $(this).val(value);
    });

    // Form validation
    $('form').submit(function(e) {
        const outstandingType = $('#outstandingType').val();
        const outstandingAmount = parseFloat($('#outstandingAmount').val()) || 0;
        
        // Outstanding validation
        if (outstandingAmount < 0) {
            e.preventDefault();
            alert('Outstanding amount cannot be negative');
            $('#outstandingAmount').focus();
            return false;
        }
        
        if (outstandingAmount > 0 && !outstandingType) {
            e.preventDefault();
            alert('Please select outstanding type when amount is greater than 0');
            $('#outstandingType').focus();
            return false;
        }
        
        if (outstandingType && outstandingAmount === 0) {
            e.preventDefault();
            alert('Outstanding amount must be greater than 0 when type is selected');
            $('#outstandingAmount').focus();
            return false;
        }
        
        // Commission validation
        const commission = parseFloat($('input[name="commission_percent"]').val());
        if (commission < 0 || commission > 100) {
            e.preventDefault();
            alert('Commission must be between 0 and 100%');
            $('input[name="commission_percent"]').focus();
            return false;
        }
        
        // Phone validation (if provided)
        const phone = $('input[name="phone"]').val();
        if (phone && !/^\d{10}$/.test(phone)) {
            e.preventDefault();
            alert('Please enter a valid 10-digit phone number');
            $('input[name="phone"]').focus();
            return false;
        }
        
        // Email validation (if provided)
        const email = $('input[name="email"]').val();
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            e.preventDefault();
            alert('Please enter a valid email address');
            $('input[name="email"]').focus();
            return false;
        }
        
        return true;
    });
});
</script>

<style>
.card { border: 1px solid #e9ecef; }
.card-header { background-color: #f8f9fa !important; border-bottom: 1px solid #e9ecef; }
.form-control:focus { border-color: #5b73e8; box-shadow: 0 0 0 0.1rem rgba(91, 115, 232, 0.25); }
.alert-info { background-color: #e7f1ff; border-color: #c2d6ff; }
.border-start { border-left-width: 4px !important; }
#outstandingSummary .badge { font-size: 0.7em; }
#summaryAmount { font-size: 2rem; font-weight: bold; }
</style>
</body>
</html>