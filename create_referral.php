<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_SESSION['current_business_id'])) {
    header('Location: select_shop.php');
    exit();
}

$current_business_id = (int) $_SESSION['current_business_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = in_array($user_role, ['admin', 'shop_manager']);

if (!$is_admin) {
    header('Location: dashboard.php');
    exit();
}

$success = $error = '';
$uploaded_file_path = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_referral'])) {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $proof_id_type = $_POST['proof_id_type'] ?? null;
    $proof_id_number = trim($_POST['proof_id_number'] ?? '');
    $commission_percent = (float)$_POST['commission_percent'];
    $referral_code = trim($_POST['referral_code']);
    $notes = trim($_POST['notes'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $initial_outstanding_type = $_POST['initial_outstanding_type'] ?? null;
    $initial_outstanding_amount = (float)$_POST['initial_outstanding_amount'] ?? 0.00;

    // Validation
    $errors = [];
    
    if (empty($full_name)) {
        $errors[] = "Full name is required";
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = "Invalid phone number format";
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if ($commission_percent < 0 || $commission_percent > 100) {
        $errors[] = "Commission percentage must be between 0 and 100";
    }
    
    if (empty($referral_code)) {
        $errors[] = "Referral code is required";
    }

    // Validate outstanding amount
    if ($initial_outstanding_amount < 0) {
        $errors[] = "Initial outstanding amount cannot be negative";
    }
    
    if ($initial_outstanding_amount > 0 && empty($initial_outstanding_type)) {
        $errors[] = "Please select outstanding type when amount is greater than 0";
    }
    
    if (empty($initial_outstanding_type) && $initial_outstanding_amount > 0) {
        $errors[] = "Please select outstanding type when amount is greater than 0";
    }
    
    if (!empty($initial_outstanding_type) && $initial_outstanding_amount == 0) {
        $errors[] = "Outstanding amount must be greater than 0 when type is selected";
    }

    // Handle file upload
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
                $uploaded_file_path = 'uploads/referral_proofs/' . $current_business_id . '/' . $new_filename;
            } else {
                $errors[] = "Failed to upload file. Please try again.";
            }
        }
    }

    if (empty($errors)) {
        try {
            // Check if phone or referral code already exists
            $check_stmt = $pdo->prepare("
                SELECT id FROM referral_person 
                WHERE business_id = ? AND (phone = ? OR referral_code = ?)
            ");
            $check_stmt->execute([$current_business_id, $phone, $referral_code]);
            
            if ($check_stmt->fetch()) {
                $error = "Phone number or referral code already exists.";
            } else {
                // Calculate initial debit and credit amounts
                $debit_amount = 0.00;
                $paid_amount = 0.00;
                $balance_due = 0.00;
                
                if ($initial_outstanding_type === 'debit') {
                    // Debit: You owe referral person
                    $debit_amount = $initial_outstanding_amount;
                    $balance_due = $initial_outstanding_amount; // Positive balance due means you owe them
                } elseif ($initial_outstanding_type === 'credit') {
                    // Credit: Referral person owes you
                    $paid_amount = $initial_outstanding_amount; // They've paid this amount
                    $balance_due = -$initial_outstanding_amount; // Negative balance due means they owe you
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO referral_person (
                        business_id, referral_code, full_name, phone, email, address, 
                        department, proof_id_type, proof_id_number, proof_id_file,
                        commission_percent, initial_outstanding_type, initial_outstanding_amount,
                        debit_amount, paid_amount, balance_due,
                        notes, is_active, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $current_business_id,
                    $referral_code,
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
                    $debit_amount,
                    $paid_amount,
                    $balance_due,
                    $notes,
                    $is_active,
                    $_SESSION['user_id']
                ]);
                
                $referral_id = $pdo->lastInsertId();
                $success = "Referral person '$full_name' added successfully!";
                
                // Clear form on success
                if ($success) {
                    $_POST = [];
                    $_FILES = [];
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Generate unique referral code
function generateReferralCode($pdo, $business_id) {
    $prefix = 'REF';
    $code = $prefix . strtoupper(substr(uniqid(), -6));
    
    // Check if code exists
    $check_stmt = $pdo->prepare("SELECT id FROM referral_person WHERE business_id = ? AND referral_code = ?");
    $check_stmt->execute([$business_id, $code]);
    
    if ($check_stmt->fetch()) {
        return generateReferralCode($pdo, $business_id); // Recursive call if duplicate
    }
    
    return $code;
}

// Auto-generate referral code
$auto_generated_code = generateReferralCode($pdo, $current_business_id);
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Add Referral Person"; ?>
<?php include('includes/head.php'); ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
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
                                    <i class="bx bx-user-plus me-2"></i> Add Referral Person
                                </h4>
                                <p class="text-muted mb-0">
                                    Add a new person to your referral program
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="referrals.php" class="btn btn-outline-secondary">
                                    <i class="bx bx-arrow-back me-1"></i> Back to List
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add Form -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show mb-4">
                                    <i class="bx bx-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    <div class="mt-2">
                                        <a href="referrals.php" class="btn btn-sm btn-outline-success me-2">
                                            <i class="bx bx-list-ul me-1"></i> View All
                                        </a>
                                        <a href="referral_add.php" class="btn btn-sm btn-primary">
                                            <i class="bx bx-plus me-1"></i> Add Another
                                        </a>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show mb-4">
                                    <i class="bx bx-error-circle me-2"></i><?= $error ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>

                                <form method="POST" id="addReferralForm" enctype="multipart/form-data">
                                    <div class="row">
                                        <div class="col-lg-8">
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
                                                            <label class="form-label">
                                                                Full Name <span class="text-danger">*</span>
                                                            </label>
                                                            <input type="text" name="full_name" class="form-control" 
                                                                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                                                                   required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">
                                                                Phone Number <span class="text-danger">*</span>
                                                            </label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">+91</span>
                                                                <input type="tel" name="phone" class="form-control" 
                                                                       pattern="[0-9]{10}" 
                                                                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                                                                       required>
                                                            </div>
                                                            <small class="text-muted">10-digit mobile number</small>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Email Address</label>
                                                            <input type="email" name="email" class="form-control" 
                                                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Referral Code <span class="text-danger">*</span></label>
                                                            <div class="input-group">
                                                                <input type="text" name="referral_code" class="form-control" 
                                                                       value="<?= htmlspecialchars($_POST['referral_code'] ?? $auto_generated_code) ?>"
                                                                       required>
                                                                <button type="button" class="btn btn-outline-secondary" id="generateCodeBtn">
                                                                    <i class="bx bx-refresh"></i> Generate
                                                                </button>
                                                            </div>
                                                            <small class="text-muted">Unique code for this referral person</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Address & Department -->
                                            <div class="card border mb-4">
                                                <div class="card-header bg-light">
                                                    <h5 class="card-title mb-0">
                                                        <i class="bx bx-map me-2"></i> Address & Department
                                                    </h5>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Department</label>
                                                            <input type="text" name="department" class="form-control" 
                                                                   value="<?= htmlspecialchars($_POST['department'] ?? '') ?>"
                                                                   placeholder="e.g., Sales, Marketing, Operations">
                                                        </div>
                                                        <div class="col-md-12 mb-3">
                                                            <label class="form-label">Address</label>
                                                            <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                                                        </div>
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
                                                                <option value="debit" <?= ($_POST['initial_outstanding_type'] ?? '') === 'debit' ? 'selected' : '' ?>>
                                                                    Debit (You owe referral person)
                                                                </option>
                                                                <option value="credit" <?= ($_POST['initial_outstanding_type'] ?? '') === 'credit' ? 'selected' : '' ?>>
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
                                                                       value="<?= htmlspecialchars($_POST['initial_outstanding_amount'] ?? '0') ?>"
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
                                                                <option value="aadhar" <?= ($_POST['proof_id_type'] ?? '') === 'aadhar' ? 'selected' : '' ?>>Aadhar Card</option>
                                                                <option value="pan" <?= ($_POST['proof_id_type'] ?? '') === 'pan' ? 'selected' : '' ?>>PAN Card</option>
                                                                <option value="voter_id" <?= ($_POST['proof_id_type'] ?? '') === 'voter_id' ? 'selected' : '' ?>>Voter ID</option>
                                                                <option value="driving_license" <?= ($_POST['proof_id_type'] ?? '') === 'driving_license' ? 'selected' : '' ?>>Driving License</option>
                                                                <option value="passport" <?= ($_POST['proof_id_type'] ?? '') === 'passport' ? 'selected' : '' ?>>Passport</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Proof of ID Number</label>
                                                            <input type="text" name="proof_id_number" class="form-control" 
                                                                   value="<?= htmlspecialchars($_POST['proof_id_number'] ?? '') ?>"
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
                                                            <div class="mt-2">
                                                                <small class="text-info">
                                                                    <i class="bx bx-info-circle me-1"></i>
                                                                    Upload scanned copy of ID proof for verification
                                                                </small>
                                                            </div>
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
                                                        <textarea name="notes" class="form-control" rows="4"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-lg-4">
                                            <!-- Commission Settings -->
                                            <div class="card border mb-4">
                                                <div class="card-header bg-light">
                                                    <h5 class="card-title mb-0">
                                                        <i class="bx bx-percent me-2"></i> Commission Settings
                                                    </h5>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <label class="form-label">
                                                            Commission Percentage <span class="text-danger">*</span>
                                                        </label>
                                                        <div class="input-group">
                                                            <input type="number" name="commission_percent" 
                                                                   class="form-control" 
                                                                   value="<?= htmlspecialchars($_POST['commission_percent'] ?? '0') ?>"
                                                                   step="0.01" min="0" max="100" required>
                                                            <span class="input-group-text">%</span>
                                                        </div>
                                                        <small class="text-muted">Default: 5% per sale</small>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="is_active" id="isActive" checked>
                                                            <label class="form-check-label" for="isActive">
                                                                Active Referral Person
                                                            </label>
                                                        </div>
                                                        <small class="text-muted">Inactive persons won't earn commissions</small>
                                                    </div>
                                                    
                                                    <div class="alert alert-info">
                                                        <i class="bx bx-info-circle me-2"></i>
                                                        <small>
                                                            The referral person will earn <span id="commissionDisplay"><?= htmlspecialchars($_POST['commission_percent'] ?? '5') ?></span>% 
                                                            commission on each sale from their referrals.
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Outstanding Summary -->
                                            <div class="card border mb-4" id="outstandingSummary" style="display: none;">
                                                <div class="card-header bg-light">
                                                    <h5 class="card-title mb-0">
                                                        <i class="bx bx-money me-2"></i> Outstanding Summary
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

                                            <!-- ID Proof Preview (if uploaded) -->
                                            <div class="card border mb-4" id="filePreviewContainer" style="display: none;">
                                                <div class="card-header bg-light">
                                                    <h5 class="card-title mb-0">
                                                        <i class="bx bx-file me-2"></i> File Preview
                                                    </h5>
                                                </div>
                                                <div class="card-body text-center">
                                                    <div id="filePreview"></div>
                                                    <small class="text-muted" id="fileName"></small>
                                                </div>
                                            </div>

                                            <!-- Quick Stats -->
                                            <div class="card border mb-4">
                                                <div class="card-header bg-light">
                                                    <h5 class="card-title mb-0">
                                                        <i class="bx bx-stats me-2"></i> Referral Program Info
                                                    </h5>
                                                </div>
                                                <div class="card-body">
                                                    <ul class="list-unstyled mb-0">
                                                        <li class="mb-2">
                                                            <i class="bx bx-check-circle text-success me-2"></i>
                                                            <small>Real-time commission tracking</small>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bx bx-check-circle text-success me-2"></i>
                                                            <small>Multiple payment methods</small>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bx bx-check-circle text-success me-2"></i>
                                                            <small>Performance analytics</small>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bx bx-check-circle text-success me-2"></i>
                                                            <small>Credit balance management</small>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bx bx-check-circle text-success me-2"></i>
                                                            <small>Transaction history</small>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bx bx-check-circle text-success me-2"></i>
                                                            <small>ID verification system</small>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>

                                            <!-- Action Buttons -->
                                            <div class="sticky-top" style="top: 20px;">
                                                <div class="card border">
                                                    <div class="card-body">
                                                        <div class="d-grid gap-2">
                                                            <button type="submit" name="add_referral" class="btn btn-primary btn-lg">
                                                                <i class="bx bx-save me-2"></i> Save Referral Person
                                                            </button>
                                                            <button type="reset" class="btn btn-outline-secondary">
                                                                <i class="bx bx-reset me-2"></i> Reset Form
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</div>
<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
$(document).ready(function() {
    // Generate referral code
    $('#generateCodeBtn').click(function() {
        $.ajax({
            url: 'ajax/generate_referral_code.php',
            method: 'GET',
            data: { business_id: <?= $current_business_id ?> },
            success: function(response) {
                if (response.success) {
                    $('input[name="referral_code"]').val(response.code);
                    showToast('success', 'New referral code generated');
                }
            }
        });
    });

    // Update commission display
    $('input[name="commission_percent"]').on('input', function() {
        $('#commissionDisplay').text($(this).val());
    });

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

    // Phone number formatting
    $('input[name="phone"]').on('input', function() {
        let value = $(this).val().replace(/\D/g, '');
        if (value.length > 10) {
            value = value.substr(0, 10);
        }
        $(this).val(value);
    });

    // File preview
    $('input[name="proof_id_file"]').on('change', function() {
        const file = this.files[0];
        if (file) {
            const fileSize = file.size / 1024 / 1024; // in MB
            const fileName = file.name;
            const fileExt = fileName.split('.').pop().toLowerCase();
            
            // Validate file size
            if (fileSize > 5) {
                showToast('error', 'File size must be less than 5MB');
                $(this).val('');
                return;
            }
            
            // Validate file type
            const allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
            if (!allowedTypes.includes(fileExt)) {
                showToast('error', 'Only PDF, JPG, PNG, GIF files are allowed');
                $(this).val('');
                return;
            }
            
            // Show file preview
            $('#fileName').text(fileName);
            $('#filePreviewContainer').show();
            
            if (fileExt === 'pdf') {
                $('#filePreview').html('<i class="bx bxs-file-pdf fs-1 text-danger"></i><br><small>PDF Document</small>');
            } else {
                // For images, create preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#filePreview').html('<img src="' + e.target.result + '" class="img-thumbnail" style="max-height: 150px;"><br><small>Image Preview</small>');
                };
                reader.readAsDataURL(file);
            }
        } else {
            $('#filePreviewContainer').hide();
        }
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
    $('#addReferralForm').submit(function(e) {
        const phone = $('input[name="phone"]').val();
        const email = $('input[name="email"]').val();
        const proofIdType = $('select[name="proof_id_type"]').val();
        const proofIdNumber = $('input[name="proof_id_number"]').val();
        const outstandingType = $('#outstandingType').val();
        const outstandingAmount = parseFloat($('#outstandingAmount').val()) || 0;
        const fileInput = $('input[name="proof_id_file"]')[0];
        
        // Phone validation
        if (!/^\d{10}$/.test(phone)) {
            e.preventDefault();
            showToast('error', 'Please enter a valid 10-digit phone number');
            $('input[name="phone"]').focus();
            return false;
        }
        
        // Email validation (if provided)
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            e.preventDefault();
            showToast('error', 'Please enter a valid email address');
            $('input[name="email"]').focus();
            return false;
        }
        
        // Commission validation
        const commission = parseFloat($('input[name="commission_percent"]').val());
        if (commission < 0 || commission > 100) {
            e.preventDefault();
            showToast('error', 'Commission must be between 0 and 100%');
            $('input[name="commission_percent"]').focus();
            return false;
        }
        
        // Outstanding validation
        if (outstandingAmount < 0) {
            e.preventDefault();
            showToast('error', 'Outstanding amount cannot be negative');
            $('#outstandingAmount').focus();
            return false;
        }
        
        if (outstandingAmount > 0 && !outstandingType) {
            e.preventDefault();
            showToast('error', 'Please select outstanding type when amount is greater than 0');
            $('#outstandingType').focus();
            return false;
        }
        
        if (outstandingType && outstandingAmount === 0) {
            e.preventDefault();
            showToast('error', 'Outstanding amount must be greater than 0 when type is selected');
            $('#outstandingAmount').focus();
            return false;
        }
        
        // ID number validation if type is selected
        if (proofIdType && !proofIdNumber) {
            e.preventDefault();
            showToast('error', 'Please enter ID number when ID type is selected');
            $('input[name="proof_id_number"]').focus();
            return false;
        }
        
        // Pattern validation for specific ID types
        if (proofIdType && proofIdNumber) {
            const pattern = $('input[name="proof_id_number"]').attr('pattern');
            if (pattern) {
                const regex = new RegExp(pattern);
                if (!regex.test(proofIdNumber)) {
                    e.preventDefault();
                    const title = $('input[name="proof_id_number"]').attr('title');
                    showToast('error', title || 'Invalid ID number format');
                    $('input[name="proof_id_number"]').focus();
                    return false;
                }
            }
        }
        
        // File validation
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            const fileSize = file.size / 1024 / 1024; // in MB
            
            if (fileSize > 5) {
                e.preventDefault();
                showToast('error', 'File size must be less than 5MB');
                return false;
            }
        }
        
        return true;
    });

    // Toast notification function
    function showToast(type, message) {
        $('.toast').remove();
        const toast = $(`
            <div class="toast align-items-center text-bg-${type} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `);
        if ($('.toast-container').length === 0) {
            $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999"></div>');
        }
        $('.toast-container').append(toast);
        const bsToast = new bootstrap.Toast(toast[0], { autohide: true, delay: 3000 });
        bsToast.show();
    }
});
</script>

<style>
.card { border: 1px solid #e9ecef; }
.card-header { background-color: #f8f9fa !important; border-bottom: 1px solid #e9ecef; }
.form-control:focus { border-color: #5b73e8; box-shadow: 0 0 0 0.1rem rgba(91, 115, 232, 0.25); }
.btn-lg { padding: 0.75rem 1.5rem; }
.sticky-top { position: sticky; z-index: 100; }
.input-group-text { background-color: #f8f9fa; }
.alert-info { background-color: #e7f1ff; border-color: #c2d6ff; }
#filePreview img { max-width: 100%; height: auto; }
.img-thumbnail { padding: 0.25rem; background-color: #fff; border: 1px solid #dee2e6; border-radius: 0.375rem; }
#outstandingSummary .badge { font-size: 0.7em; }
#summaryAmount { font-size: 2rem; font-weight: bold; }
</style>
</body>
</html>