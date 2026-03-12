<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager'])) {
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$current_business_id = $_SESSION['current_business_id'] ?? null;

if (!$current_business_id) {
    $_SESSION['error'] = "Please select a business first.";
    header('Location: select_shop.php');
    exit();
}

$success = $error = '';

// Initialize default GST settings if not exists
try {
    $check_stmt = $pdo->prepare("SELECT id FROM gst_settings WHERE business_id = ? AND shop_id IS NULL");
    $check_stmt->execute([$current_business_id]);
    
    if ($check_stmt->fetchColumn() === false) {
        $init_stmt = $pdo->prepare("INSERT INTO gst_settings (business_id, is_gst_enabled, is_inclusive, status) VALUES (?, 1, 1, 'active')");
        $init_stmt->execute([$current_business_id]);
    }
} catch (Exception $e) {
    // Ignore initialization errors
}

// Get GST settings
$settings_stmt = $pdo->prepare("
    SELECT gs.*, s.shop_name 
    FROM gst_settings gs
    LEFT JOIN shops s ON gs.shop_id = s.id
    WHERE gs.business_id = ?
    ORDER BY gs.shop_id IS NULL DESC, s.shop_name
");
$settings_stmt->execute([$current_business_id]);
$gst_settings = $settings_stmt->fetchAll();

// Add/Edit GST Rate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $hsn_code = trim($_POST['hsn_code']);
    $cgst = (float)$_POST['cgst_rate'];
    $sgst = (float)$_POST['sgst_rate'];
    $igst = (float)$_POST['igst_rate'];
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (empty($hsn_code)) {
        $error = "HSN Code is required.";
    } else {
        try {
            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE gst_rates 
                    SET hsn_code = ?, cgst_rate = ?, sgst_rate = ?, igst_rate = ?, 
                        description = ?, status = ?, updated_at = NOW()
                    WHERE id = ? AND business_id = ?
                ");
                $stmt->execute([$hsn_code, $cgst, $sgst, $igst, $description, $status, $id, $current_business_id]);
                $success = "GST Rate updated successfully!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO gst_rates 
                    (hsn_code, cgst_rate, sgst_rate, igst_rate, description, status, business_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$hsn_code, $cgst, $sgst, $igst, $description, $status, $current_business_id]);
                $success = "GST Rate added successfully!";
            }
        } catch (PDOException $e) {
            $error = "HSN Code already exists in your business.";
        }
    }
}

// Delete GST Rate
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $check_usage = $pdo->prepare("SELECT COUNT(*) FROM products WHERE gst_id = ? AND business_id = ?");
        $check_usage->execute([$id, $current_business_id]);
        
        if ($check_usage->fetchColumn() > 0) {
            $error = "Cannot delete: This GST rate is being used by products.";
        } else {
            $pdo->prepare("DELETE FROM gst_rates WHERE id = ? AND business_id = ?")->execute([$id, $current_business_id]);
            $success = "GST Rate deleted successfully!";
        }
    } catch (Exception $e) {
        $error = "Error deleting GST rate: " . $e->getMessage();
    }
}

// Update GST Settings
if (isset($_GET['toggle_gst'])) {
    $setting_id = (int)$_GET['toggle_gst'];
    $field = $_GET['field'] ?? '';
    
    if (in_array($field, ['is_gst_enabled', 'is_inclusive'])) {
        try {
            $stmt = $pdo->prepare("
                UPDATE gst_settings 
                SET $field = NOT $field, updated_at = NOW() 
                WHERE id = ? AND business_id = ?
            ");
            $stmt->execute([$setting_id, $current_business_id]);
            $success = "GST setting updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating setting: " . $e->getMessage();
        }
    }
}

// Fetch GST rates for current business only
$gst_rates = $pdo->prepare("
    SELECT gr.*, 
           (SELECT COUNT(*) FROM products p WHERE p.gst_id = gr.id AND p.business_id = ?) as usage_count
    FROM gst_rates gr
    WHERE gr.business_id = ?
    ORDER BY gr.hsn_code
");
$gst_rates->execute([$current_business_id, $current_business_id]);
$gst_rates = $gst_rates->fetchAll();
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "GST Management";
include('includes/head.php') 
?>
<!-- Add SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
.toggle-btn {
    width: 60px;
    height: 30px;
    position: relative;
    display: inline-block;
}
.toggle-input {
    display: none;
}
.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 34px;
}
.toggle-slider:before {
    position: absolute;
    content: "";
    height: 22px;
    width: 22px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}
.toggle-input:checked + .toggle-slider {
    background-color: #0d6efd;
}
.toggle-input:checked + .toggle-slider:before {
    transform: translateX(30px);
}
</style>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php') ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php') ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">
                                <i class="bx bx-calculator me-2"></i> GST Management
                                <small class="text-muted ms-2">
                                    <i class="bx bx-store me-1"></i>
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#gstModal">
                                <i class="bx bx-plus me-1"></i> Add GST Rate
                            </button>
                        </div>
                    </div>
                </div>

                <!-- GST Settings Card -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-cog me-2"></i> GST Configuration
                                    <small class="text-muted">Configure how GST applies to your business</small>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Shop/Location</th>
                                                <th>GST Enabled</th>
                                                <th>Price Type</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($gst_settings)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center py-4">
                                                    <i class="bx bx-cog fs-1 text-muted mb-2 d-block"></i>
                                                    <p class="text-muted">No GST settings configured</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($gst_settings as $setting): ?>
                                            <tr>
                                                <td>
                                                    <strong>
                                                        <?= $setting['shop_name'] ? htmlspecialchars($setting['shop_name']) : '<i>All Shops (Default)</i>' ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <label class="toggle-btn">
                                                        <input type="checkbox" class="toggle-input" 
                                                               id="gst_enabled_<?= $setting['id'] ?>"
                                                               data-id="<?= $setting['id'] ?>" 
                                                               data-field="is_gst_enabled"
                                                               <?= $setting['is_gst_enabled'] ? 'checked' : '' ?>>
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                    <small class="ms-2 text-muted">
                                                        <?= $setting['is_gst_enabled'] ? 'Enabled' : 'Disabled' ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <label class="toggle-btn">
                                                        <input type="checkbox" class="toggle-input" 
                                                               id="gst_inclusive_<?= $setting['id'] ?>"
                                                               data-id="<?= $setting['id'] ?>" 
                                                               data-field="is_inclusive"
                                                               <?= $setting['is_inclusive'] ? 'checked' : '' ?>>
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                    <small class="ms-2 text-muted">
                                                        <?= $setting['is_inclusive'] ? 'Inclusive' : 'Exclusive' ?>
                                                    </small>
                                                    <?php if ($setting['is_inclusive']): ?>
                                                    <br><small class="text-success">(GST included in product price)</small>
                                                    <?php else: ?>
                                                    <br><small class="text-info">(GST added separately at billing)</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php if ($setting['shop_id'] === null): ?>
                                                        <i class="bx bx-info-circle"></i> These settings apply to all shops unless overridden
                                                        <?php else: ?>
                                                        <i class="bx bx-store"></i> Shop-specific override
                                                        <?php endif; ?>
                                                        <br>Updated: <?= date('d M Y', strtotime($setting['updated_at'])) ?>
                                                    </small>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="alert alert-info mt-3">
                                    <i class="bx bx-info-circle me-2"></i>
                                    <strong>About GST Settings:</strong>
                                    <ul class="mb-0">
                                        <li><strong>GST Enabled:</strong> Turn GST on/off for specific shops or all shops</li>
                                        <li><strong>Inclusive Pricing:</strong> GST is included in product prices (Recommended for retail)</li>
                                        <li><strong>Exclusive Pricing:</strong> GST is added separately at billing (Used for B2B)</li>
                                        <li>Shop-specific settings override the default (All Shops) settings</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GST Rates Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bx bx-hash me-2"></i> GST Rates & HSN Codes
                                        <span class="badge bg-primary ms-2"><?= count($gst_rates) ?> rates</span>
                                    </h5>
                                    <div class="text-muted">
                                        <i class="bx bx-building me-1"></i> Business: <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'N/A') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show">
                                    <i class="bx bx-check-circle me-2"></i><?= $success ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>

                                <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show">
                                    <i class="bx bx-error-circle me-2"></i><?= htmlspecialchars($error) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>

                                <div class="table-responsive">
                                    <table class="table table-hover align-middle w-100">
                                        <thead class="table-light">
                                            <tr>
                                                <th>HSN Code</th>
                                                <th class="text-center">CGST</th>
                                                <th class="text-center">SGST</th>
                                                <th class="text-center">IGST</th>
                                                <th class="text-center">Total</th>
                                                <th>Description</th>
                                                <th class="text-center">Usage</th>
                                                <th class="text-center">Status</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($gst_rates) > 0): ?>
                                                <?php foreach ($gst_rates as $g): ?>
                                                <tr class="gst-row" data-id="<?= $g['id'] ?>">
                                                    <td>
                                                        <strong class="text-primary"><?= htmlspecialchars($g['hsn_code']) ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1">
                                                            <?= number_format($g['cgst_rate'], 2) ?>%
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1">
                                                            <?= number_format($g['sgst_rate'], 2) ?>%
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-1">
                                                            <?= number_format($g['igst_rate'], 2) ?>%
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-success rounded-pill px-3 py-2 fs-6">
                                                            <?= number_format($g['cgst_rate'] + $g['sgst_rate'] + $g['igst_rate'], 2) ?>%
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted">
                                                            <?= $g['description'] ? htmlspecialchars($g['description']) : '—' ?>
                                                        </small>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info rounded-pill px-3 py-1">
                                                            <?= $g['usage_count'] ?> products
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?= $g['status'] === 'active' ? 'success' : 'secondary' ?> bg-opacity-10 text-<?= $g['status'] === 'active' ? 'success' : 'secondary' ?> px-3 py-1">
                                                            <i class="bx bx-circle me-1"></i><?= ucfirst($g['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
    <div class="d-flex flex-column flex-sm-row gap-1 justify-content-center">
        <button class="btn btn-outline-warning btn-sm edit-gst-btn"
                data-id="<?= $g['id'] ?>"
                data-hsn="<?= htmlspecialchars($g['hsn_code']) ?>"
                data-cgst="<?= $g['cgst_rate'] ?>"
                data-sgst="<?= $g['sgst_rate'] ?>"
                data-igst="<?= $g['igst_rate'] ?>"
                data-desc="<?= htmlspecialchars($g['description'] ?? '') ?>"
                data-status="<?= $g['status'] ?>"
                data-bs-toggle="tooltip"
                title="Edit Rate">
            <i class="bx bx-edit"></i>
            <span class="d-none d-sm-inline ms-1">Edit</span>
        </button>
        <button class="btn btn-outline-danger btn-sm delete-gst-btn"
                data-id="<?= $g['id'] ?>"
                data-hsn="<?= htmlspecialchars($g['hsn_code']) ?>"
                data-usage="<?= $g['usage_count'] ?>"
                data-bs-toggle="tooltip"
                title="Delete Rate">
            <i class="bx bx-trash"></i>
            <span class="d-none d-sm-inline ms-1">Delete</span>
        </button>
    </div>
</td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="9" class="text-center py-5">
                                                        <div class="empty-state">
                                                            <i class="bx bx-hash fs-1 text-muted mb-3"></i>
                                                            <h5>No GST Rates Found</h5>
                                                            <p class="text-muted">Add your first GST rate to start using tax calculations</p>
                                                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#gstModal">
                                                                <i class="bx bx-plus me-1"></i> Add GST Rate
                                                            </button>
                                                        </div>
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
        <?php include('includes/footer.php') ?>
    </div>
</div>

<!-- Add/Edit GST Rate Modal -->
<div class="modal fade" id="gstModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="id" id="gstId">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bx bx-plus-circle me-2"></i>
                    <span id="modalTitle">Add GST Rate</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">HSN Code <span class="text-danger">*</span></label>
                        <input type="text" name="hsn_code" id="hsnCode" class="form-control" 
                               placeholder="Enter HSN code" required maxlength="10">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">CGST %</label>
                        <input type="number" step="0.01" name="cgst_rate" id="cgstRate" 
                               class="form-control" value="9" min="0" max="100" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">SGST %</label>
                        <input type="number" step="0.01" name="sgst_rate" id="sgstRate" 
                               class="form-control" value="9" min="0" max="100" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">IGST %</label>
                        <input type="number" step="0.01" name="igst_rate" id="igstRate" 
                               class="form-control" value="0" min="0" max="100" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description (Optional)</label>
                        <textarea name="description" id="gstDesc" class="form-control" 
                                  rows="2" placeholder="Description of this HSN code..."></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Status</label>
                        <select name="status" id="gstStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bx bx-save me-1"></i> Save GST Rate
                </button>
            </div>
        </form>
    </div>
</div>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>
<!-- Add SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
<script>
$(document).ready(function() {
    // Tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Edit GST Rate
    $('.edit-gst-btn').click(function() {
        $('#modalTitle').text('Edit GST Rate');
        $('#gstId').val($(this).data('id'));
        $('#hsnCode').val($(this).data('hsn'));
        $('#cgstRate').val($(this).data('cgst'));
        $('#sgstRate').val($(this).data('sgst'));
        $('#igstRate').val($(this).data('igst'));
        $('#gstDesc').val($(this).data('desc'));
        $('#gstStatus').val($(this).data('status'));
        $('#gstModal').modal('show');
    });

    // Delete GST Rate with confirmation
    $('.delete-gst-btn').click(function() {
        const id = $(this).data('id');
        const hsn = $(this).data('hsn');
        const usage = $(this).data('usage');
        
        if (usage > 0) {
            showToast('error', `Cannot delete: ${hsn} is used by ${usage} product(s)`);
            return;
        }
        
        Swal.fire({
            title: 'Delete GST Rate?',
            html: `<div class="text-start">
                    <p>Are you sure you want to delete:</p>
                    <div class="alert alert-light border">
                        <strong class="d-block">${hsn}</strong>
                        <small class="text-muted">This action cannot be undone</small>
                    </div>
                   </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel',
            width: 400
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `?delete=${id}`;
            }
        });
    });

    // GST Settings Toggle - Fixed with proper state management
    $('.toggle-input').change(function() {
        const settingId = $(this).data('id');
        const field = $(this).data('field');
        const isChecked = $(this).is(':checked');
        const toggle = $(this);
        const row = toggle.closest('tr');
        
        // Show loading state
        toggle.prop('disabled', true);
        
        // Get current text elements
        const statusText = row.find(`small:contains('Enabled'), small:contains('Disabled'), small:contains('Inclusive'), small:contains('Exclusive')`).first();
        const descriptionText = row.find('.text-success, .text-info').first();
        
        // Send AJAX request
        $.ajax({
            url: 'ajax/gst_toggle.php',
            method: 'POST',
            data: {
                setting_id: settingId,
                field: field,
                value: isChecked ? 1 : 0,
                business_id: <?= $current_business_id ?>
            },
            success: function(response) {
                toggle.prop('disabled', false);
                
                if (response.success) {
                    // Update UI based on field type
                    if (field === 'is_gst_enabled') {
                        if (isChecked) {
                            statusText.text('Enabled').removeClass('text-muted').addClass('text-success');
                        } else {
                            statusText.text('Disabled').removeClass('text-success').addClass('text-muted');
                        }
                        showToast('success', response.message || 'GST setting updated');
                    } else if (field === 'is_inclusive') {
                        if (isChecked) {
                            statusText.text('Inclusive').removeClass('text-muted').addClass('text-success');
                            if (descriptionText.length) {
                                descriptionText.text('(GST included in product price)').removeClass('text-info').addClass('text-success');
                            }
                        } else {
                            statusText.text('Exclusive').removeClass('text-success').addClass('text-muted');
                            if (descriptionText.length) {
                                descriptionText.text('(GST added separately at billing)').removeClass('text-success').addClass('text-info');
                            }
                        }
                        showToast('success', response.message || 'Price type updated');
                    }
                } else {
                    // Revert toggle on error
                    toggle.prop('checked', !isChecked);
                    showToast('error', response.message || 'Failed to update');
                }
            },
            error: function() {
                toggle.prop('disabled', false);
                toggle.prop('checked', !isChecked);
                showToast('error', 'Network error. Please try again.');
            }
        });
    });

    // Reset modal when closed
    $('#gstModal').on('hidden.bs.modal', function () {
        $('#modalTitle').text('Add GST Rate');
        $('#gstId').val('');
        $('#hsnCode').val('');
        $('#cgstRate').val('9');
        $('#sgstRate').val('9');
        $('#igstRate').val('0');
        $('#gstDesc').val('');
        $('#gstStatus').val('active');
        $('#totalDisplay').remove();
    });

    // Auto-calculate total on rate inputs
    $('#gstModal').on('input', 'input[type="number"]', function() {
        const cgst = parseFloat($('#cgstRate').val()) || 0;
        const sgst = parseFloat($('#sgstRate').val()) || 0;
        const igst = parseFloat($('#igstRate').val()) || 0;
        const total = cgst + sgst + igst;
        
        if ($('#totalDisplay').length === 0) {
            $('<div class="alert alert-info mt-2 py-1 px-2 d-inline-block" id="totalDisplay">Total GST: <strong>' + total.toFixed(2) + '%</strong></div>')
                .insertAfter($('#igstRate').parent());
        } else {
            $('#totalDisplay').html('Total GST: <strong>' + total.toFixed(2) + '%</strong>');
        }
    });

    // Make buttons responsive for small screens
    function adjustButtonsForScreen() {
        const screenWidth = $(window).width();
        const btnGroup = $('.btn-group');
        const tableCells = $('td.text-center');
        
        if (screenWidth < 768) {
            // Stack buttons vertically on small screens
            btnGroup.removeClass('btn-group').addClass('btn-group-vertical d-flex');
            tableCells.addClass('small');
            $('.table-responsive').addClass('small');
        } else {
            btnGroup.removeClass('btn-group-vertical d-flex').addClass('btn-group');
            tableCells.removeClass('small');
            $('.table-responsive').removeClass('small');
        }
    }
    
    // Run on load and resize
    adjustButtonsForScreen();
    $(window).resize(adjustButtonsForScreen);
});

// Improved Toast notification - Right top corner
function showToast(type, message) {
    // Remove existing toasts
    $('.custom-toast').remove();
    
    const bgColor = type === 'success' ? 'bg-success' : 'bg-danger';
    const icon = type === 'success' ? '✓' : '⚠';
    const iconClass = type === 'success' ? 'bx-check-circle' : 'bx-error-circle';
    
    const toast = $(`
        <div class="custom-toast">
            <div class="toast ${bgColor} text-white border-0 fade show" role="alert">
                <div class="toast-body">
                    <div class="toast-content">
                        <i class="bx ${iconClass} toast-icon"></i>
                        <span class="toast-message">${message}</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
            </div>
        </div>
    `);
    
    $('body').append(toast);
    
    // Auto remove after 3 seconds with fade out animation
    setTimeout(() => {
        toast.find('.toast').css('animation', 'slideOutRight 0.3s ease forwards');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
    
    // Manual close with animation
    toast.find('.btn-close').click(function() {
        toast.find('.toast').css('animation', 'slideOutRight 0.3s ease forwards');
        setTimeout(() => toast.remove(), 300);
    });
}

// Show success/error messages from PHP
<?php if ($success): ?>
setTimeout(() => showToast('success', '<?= addslashes($success) ?>'), 300);
<?php endif; ?>

<?php if ($error): ?>
setTimeout(() => showToast('error', '<?= addslashes($error) ?>'), 300);
<?php endif; ?>
</script>
<style>
/* Responsive table */
@media (max-width: 767.98px) {
    .table-responsive.small table {
        font-size: 0.875rem;
    }
    
    .table-responsive.small .btn-group-vertical {
        width: 100%;
    }
    
    .table-responsive.small .btn-group-vertical .btn {
        width: 100%;
        margin-bottom: 2px;
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
    
    .table-responsive.small td,
    .table-responsive.small th {
        padding: 0.5rem 0.25rem;
    }
    
    .table-responsive.small .badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
    
    /* Stack GST rates on mobile */
    .gst-row td:nth-child(2),
    .gst-row td:nth-child(3),
    .gst-row td:nth-child(4) {
        text-align: left !important;
    }
    
    .gst-row td:nth-child(2)::before { content: "CGST: "; font-weight: bold; }
    .gst-row td:nth-child(3)::before { content: "SGST: "; font-weight: bold; }
    .gst-row td:nth-child(4)::before { content: "IGST: "; font-weight: bold; }
    
    .gst-row td:nth-child(2),
    .gst-row td:nth-child(3),
    .gst-row td:nth-child(4) {
        display: block;
        border: none;
        padding: 0.25rem 0.5rem;
    }
    
    .gst-row td:nth-child(1) {
        border-bottom: 1px solid #dee2e6;
    }
}

/* Toast styling */
.custom-toast {
    
    
    z-index: 9999;
    animation: slideDown 0.3s ease;
}

.custom-toast .toast {
    min-width: 300px;
    max-width: 90vw;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

@keyframes slideDown {
    from {
        transform: translate(-50%, -100%);
        opacity: 0;
    }
    to {
        transform: translate(-50%, 0);
        opacity: 1;
    }
}

/* Button groups */
.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Toggle button improvements */
.toggle-btn {
    width: 50px;
    height: 26px;
}

.toggle-slider:before {
    height: 18px;
    width: 18px;
    left: 4px;
    bottom: 4px;
}

.toggle-input:checked + .toggle-slider:before {
    transform: translateX(24px);
}

/* Better alignment for toggle labels */
td:nth-child(2), td:nth-child(3) {
    vertical-align: middle;
}

td .toggle-btn + small {
    display: inline-block;
    vertical-align: middle;
    margin-left: 8px;
}

/* Status badges */
.badge.bg-opacity-10 {
    opacity: 0.9;
}

/* Hover effects */
.table-hover tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.05) !important;
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

/* Modal improvements */
#gstModal .modal-dialog {
    max-width: 500px;
}

#gstModal .form-control,
#gstModal .form-select {
    padding: 0.5rem 0.75rem;
    font-size: 0.9rem;
}

/* Empty state */
.empty-state {
    padding: 2rem 1rem;
}

.empty-state i {
    font-size: 3rem;
    opacity: 0.5;
}
/* Toast styling */
.custom-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    animation: slideInRight 0.3s ease;
    max-width: 350px;
}

.custom-toast .toast {
    min-width: 300px;
    max-width: 100%;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    border-radius: 0.375rem;
    overflow: hidden;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOutRight {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}

/* Responsive toast positioning */
@media (max-width: 576px) {
    .custom-toast {
        top: 10px;
        right: 10px;
        left: 10px;
        max-width: calc(100% - 20px);
    }
    
    .custom-toast .toast {
        min-width: auto;
        width: 100%;
    }
}

/* Toast body styling */
.custom-toast .toast-body {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
}

.custom-toast .toast-content {
    display: flex;
    align-items: center;
    flex-grow: 1;
}

.custom-toast .toast-icon {
    font-size: 1.25rem;
    margin-right: 0.75rem;
}

.custom-toast .toast-message {
    flex-grow: 1;
    font-size: 0.875rem;
    line-height: 1.4;
}

.custom-toast .btn-close {
    margin-left: 0.75rem;
    padding: 0.5rem;
    background-size: 0.75rem;
}
</style>
</body>
</html>