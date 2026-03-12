<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Only admin can access settings
if ($_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

$business_id = $_SESSION['business_id'] ?? 1;
$message = '';
$message_type = '';

// ========== BANK ACCOUNT MANAGEMENT FUNCTIONS ==========

// Add new bank account
if (isset($_POST['add_bank_account'])) {
    try {
        $shop_id = $_POST['shop_id'] ?? null;
        $bank_name = $_POST['bank_name'] ?? '';
        $account_number = $_POST['account_number'] ?? '';
        $account_holder_name = $_POST['account_holder_name'] ?? '';
        $ifsc_code = $_POST['ifsc_code'] ?? '';
        $branch_name = $_POST['branch_name'] ?? '';
        $account_type = $_POST['account_type'] ?? '';
        $upi_id = $_POST['upi_id'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        // If setting as default, remove default from other accounts
        if ($is_default) {
            $reset_sql = "UPDATE bank_accounts SET is_default = 0 WHERE business_id = ?";
            if ($shop_id) {
                $reset_sql .= " AND shop_id = ?";
                $reset_stmt = $pdo->prepare($reset_sql);
                $reset_stmt->execute([$business_id, $shop_id]);
            } else {
                $reset_sql .= " AND shop_id IS NULL";
                $reset_stmt = $pdo->prepare($reset_sql);
                $reset_stmt->execute([$business_id]);
            }
        }
        
        // Insert new bank account
        $sql = "INSERT INTO bank_accounts (
                business_id, shop_id, bank_name, account_number, 
                account_holder_name, ifsc_code, branch_name, 
                account_type, upi_id, is_active, is_default
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $business_id, $shop_id, $bank_name, $account_number,
            $account_holder_name, $ifsc_code, $branch_name,
            $account_type, $upi_id, $is_active, $is_default
        ]);
        
        $message = "Bank account added successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Update bank account
if (isset($_POST['update_bank_account'])) {
    try {
        $account_id = $_POST['account_id'];
        $shop_id = $_POST['shop_id'] ?? null;
        $bank_name = $_POST['bank_name'] ?? '';
        $account_number = $_POST['account_number'] ?? '';
        $account_holder_name = $_POST['account_holder_name'] ?? '';
        $ifsc_code = $_POST['ifsc_code'] ?? '';
        $branch_name = $_POST['branch_name'] ?? '';
        $account_type = $_POST['account_type'] ?? '';
        $upi_id = $_POST['upi_id'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        // If setting as default, remove default from other accounts
        if ($is_default) {
            $reset_sql = "UPDATE bank_accounts SET is_default = 0 WHERE business_id = ?";
            if ($shop_id) {
                $reset_sql .= " AND shop_id = ?";
                $reset_stmt = $pdo->prepare($reset_sql);
                $reset_stmt->execute([$business_id, $shop_id]);
            } else {
                $reset_sql .= " AND shop_id IS NULL";
                $reset_stmt = $pdo->prepare($reset_sql);
                $reset_stmt->execute([$business_id]);
            }
        }
        
        // Update bank account
        $sql = "UPDATE bank_accounts SET 
                bank_name = ?, account_number = ?, account_holder_name = ?,
                ifsc_code = ?, branch_name = ?, account_type = ?,
                upi_id = ?, is_active = ?, is_default = ?, updated_at = NOW()
                WHERE id = ? AND business_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $bank_name, $account_number, $account_holder_name,
            $ifsc_code, $branch_name, $account_type,
            $upi_id, $is_active, $is_default,
            $account_id, $business_id
        ]);
        
        $message = "Bank account updated successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Delete bank account
if (isset($_GET['delete_account'])) {
    try {
        $account_id = $_GET['delete_account'];
        $shop_id = $_GET['shop_id'] ?? null;
        
        $sql = "DELETE FROM bank_accounts WHERE id = ? AND business_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$account_id, $business_id]);
        
        $message = "Bank account deleted successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Toggle bank account status
if (isset($_GET['toggle_account'])) {
    try {
        $account_id = $_GET['toggle_account'];
        $shop_id = $_GET['shop_id'] ?? null;
        
        // Get current status
        $sql = "SELECT is_active FROM bank_accounts WHERE id = ? AND business_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$account_id, $business_id]);
        $account = $stmt->fetch();
        
        if ($account) {
            $new_status = $account['is_active'] ? 0 : 1;
            
            $update_sql = "UPDATE bank_accounts SET is_active = ?, updated_at = NOW() 
                          WHERE id = ? AND business_id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$new_status, $account_id, $business_id]);
            
            $message = "Bank account status updated!";
            $message_type = "success";
        }
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
}

// ========== EXISTING INVOICE SETTINGS FUNCTIONS ==========

// Handle form submission for invoice settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_bank_account']) && !isset($_POST['update_bank_account'])) {
    try {
        $company_name = $_POST['company_name'] ?? '';
        $company_address = $_POST['company_address'] ?? '';
        $company_phone = $_POST['company_phone'] ?? '';
        $company_email = $_POST['company_email'] ?? '';
        $company_website = $_POST['company_website'] ?? '';
        $gst_number = $_POST['gst_number'] ?? '';
        $pan_number = $_POST['pan_number'] ?? '';
        $invoice_terms = $_POST['invoice_terms'] ?? '';
        $invoice_footer = $_POST['invoice_footer'] ?? '';
        $invoice_prefix = $_POST['invoice_prefix'] ?? 'INV';
        $qr_code_data = $_POST['qr_code_data'] ?? '';
        $shop_id = $_POST['shop_id'] ?? null; // Get shop_id from form
        
        // Check if shop belongs to current business
        if ($shop_id && $shop_id != 0) {
            $shop_check = $pdo->prepare("SELECT id FROM shops WHERE id = ? AND business_id = ?");
            $shop_check->execute([$shop_id, $business_id]);
            if (!$shop_check->fetch()) {
                throw new Exception("Invalid shop selected.");
            }
        }
        
        // Check if settings already exist for this business and shop
        if ($shop_id && $shop_id != 0) {
            $check_sql = "SELECT id FROM invoice_settings WHERE business_id = ? AND shop_id = ? LIMIT 1";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$business_id, $shop_id]);
            $exists = $check_stmt->fetch();
        } else {
            $check_sql = "SELECT id FROM invoice_settings WHERE business_id = ? AND shop_id IS NULL LIMIT 1";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$business_id]);
            $exists = $check_stmt->fetch();
        }
        
        if ($exists) {
            // Update existing settings
            $sql = "UPDATE invoice_settings SET 
                    company_name = ?,
                    company_address = ?,
                    company_phone = ?,
                    company_email = ?,
                    company_website = ?,
                    gst_number = ?,
                    pan_number = ?,
                    invoice_terms = ?,
                    invoice_footer = ?,
                    invoice_prefix = ?,
                    qr_code_data = ?,
                    updated_at = NOW()
                    WHERE business_id = ? AND " . (($shop_id && $shop_id != 0) ? "shop_id = ?" : "shop_id IS NULL");
        } else {
            // Insert new settings
            $sql = "INSERT INTO invoice_settings (
                    business_id, shop_id, company_name, company_address, company_phone, 
                    company_email, company_website, gst_number, pan_number, 
                    invoice_terms, invoice_footer, invoice_prefix, qr_code_data
                    ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )";
        }
        
        $stmt = $pdo->prepare($sql);
        
        if ($exists) {
            if ($shop_id && $shop_id != 0) {
                $stmt->execute([
                    $company_name, $company_address, $company_phone, $company_email,
                    $company_website, $gst_number, $pan_number, $invoice_terms,
                    $invoice_footer, $invoice_prefix, $qr_code_data,
                    $business_id, $shop_id
                ]);
            } else {
                $stmt->execute([
                    $company_name, $company_address, $company_phone, $company_email,
                    $company_website, $gst_number, $pan_number, $invoice_terms,
                    $invoice_footer, $invoice_prefix, $qr_code_data,
                    $business_id
                ]);
            }
        } else {
            $stmt->execute([
                $business_id, ($shop_id && $shop_id != 0) ? $shop_id : null, $company_name, $company_address, $company_phone,
                $company_email, $company_website, $gst_number, $pan_number,
                $invoice_terms, $invoice_footer, $invoice_prefix, $qr_code_data
            ]);
        }
        
        $message = "Invoice settings saved successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Handle logo upload
if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === 0) {
    $shop_id = $_POST['logo_shop_id'] ?? null;
    if ($shop_id == 0) $shop_id = null;
    
    $upload_dir = 'uploads/logos/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = $_FILES['company_logo']['type'];
    
    if (in_array($file_type, $allowed_types)) {
        // Get original file extension
        $file_info = pathinfo($_FILES['company_logo']['name']);
        $extension = strtolower($file_info['extension']);
        
        // Map MIME types to extensions if extension is missing
        if (empty($extension)) {
            switch($file_type) {
                case 'image/jpeg':
                case 'image/jpg':
                    $extension = 'jpg';
                    break;
                case 'image/png':
                    $extension = 'png';
                    break;
                case 'image/gif':
                    $extension = 'gif';
                    break;
                case 'image/webp':
                    $extension = 'webp';
                    break;
                default:
                    $extension = 'jpg';
            }
        }
        
        $file_name = 'logo_' . $business_id . ($shop_id ? '_shop_' . $shop_id : '') . '_' . time() . '.' . $extension;
        $target_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $target_path)) {
            // Update database with logo path
            $sql = "INSERT INTO invoice_settings (business_id, shop_id, logo_path, updated_at) 
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE logo_path = ?, updated_at = NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$business_id, $shop_id, $target_path, $target_path]);
            
            $message = "Logo uploaded successfully!";
            $message_type = "success";
        } else {
            $message = "Failed to upload logo. Please try again.";
            $message_type = "danger";
        }
    } else {
        $message = "Invalid file type. Only JPG, JPEG, PNG, GIF & WEBP are allowed.";
        $message_type = "danger";
    }
}

// Handle QR code upload
if (isset($_FILES['qr_code_file']) && $_FILES['qr_code_file']['error'] === 0) {
    $shop_id = $_POST['qr_shop_id'] ?? null;
    if ($shop_id == 0) $shop_id = null;
    
    $upload_dir = 'uploads/qrcodes/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = $_FILES['qr_code_file']['type'];
    
    if (in_array($file_type, $allowed_types)) {
        // Get original file extension
        $file_info = pathinfo($_FILES['qr_code_file']['name']);
        $extension = strtolower($file_info['extension']);
        
        // Map MIME types to extensions if extension is missing
        if (empty($extension)) {
            switch($file_type) {
                case 'image/jpeg':
                case 'image/jpg':
                    $extension = 'jpg';
                    break;
                case 'image/png':
                    $extension = 'png';
                    break;
                case 'image/gif':
                    $extension = 'gif';
                    break;
                case 'image/webp':
                    $extension = 'webp';
                    break;
                default:
                    $extension = 'jpg';
            }
        }
        
        $file_name = 'qr_' . $business_id . ($shop_id ? '_shop_' . $shop_id : '') . '_' . time() . '.' . $extension;
        $target_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['qr_code_file']['tmp_name'], $target_path)) {
            // Update database with QR code path
            $sql = "INSERT INTO invoice_settings (business_id, shop_id, qr_code_path, updated_at) 
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE qr_code_path = ?, updated_at = NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$business_id, $shop_id, $target_path, $target_path]);
            
            $message = "QR Code uploaded successfully!";
            $message_type = "success";
        } else {
            $message = "Failed to upload QR code. Please try again.";
            $message_type = "danger";
        }
    } else {
        $message = "Invalid file type. Only JPG, JPEG, PNG, GIF & WEBP are allowed.";
        $message_type = "danger";
    }
}

// Fetch shops for current business
$shops_stmt = $pdo->prepare("SELECT id, shop_name, shop_code FROM shops WHERE business_id = ? AND is_active = 1 ORDER BY shop_name");
$shops_stmt->execute([$business_id]);
$shops = $shops_stmt->fetchAll();

// Initialize selected shop
$selected_shop_id = $_GET['shop_id'] ?? null;
if ($selected_shop_id == 0) $selected_shop_id = null;
if (!$selected_shop_id && !empty($shops)) {
    $selected_shop_id = $shops[0]['id'];
}

// Fetch current settings
$settings = [];
if ($selected_shop_id) {
    // Get shop-specific settings
    $sql = "SELECT * FROM invoice_settings WHERE business_id = ? AND shop_id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$business_id, $selected_shop_id]);
    $settings = $stmt->fetch() ?: [];
    
    // If no shop-specific settings, get business default settings
    if (empty($settings)) {
        $sql = "SELECT * FROM invoice_settings WHERE business_id = ? AND shop_id IS NULL LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$business_id]);
        $settings = $stmt->fetch() ?: [];
    }
} else {
    // Get business default settings
    $sql = "SELECT * FROM invoice_settings WHERE business_id = ? AND shop_id IS NULL LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$business_id]);
    $settings = $stmt->fetch() ?: [];
}

// Fetch bank accounts for current shop/business
if ($selected_shop_id) {
    $bank_accounts_sql = "SELECT * FROM bank_accounts WHERE business_id = ? AND shop_id = ? ORDER BY is_default DESC, is_active DESC, bank_name";
    $bank_accounts_stmt = $pdo->prepare($bank_accounts_sql);
    $bank_accounts_stmt->execute([$business_id, $selected_shop_id]);
} else {
    $bank_accounts_sql = "SELECT * FROM bank_accounts WHERE business_id = ? AND shop_id IS NULL ORDER BY is_default DESC, is_active DESC, bank_name";
    $bank_accounts_stmt = $pdo->prepare($bank_accounts_sql);
    $bank_accounts_stmt->execute([$business_id]);
}
$bank_accounts = $bank_accounts_stmt->fetchAll();

// If no settings found, use default values
if (empty($settings)) {
    // Try to get business info as fallback
    $business_sql = "SELECT business_name, phone, address, gstin FROM businesses WHERE id = ?";
    $business_stmt = $pdo->prepare($business_sql);
    $business_stmt->execute([$business_id]);
    $business = $business_stmt->fetch() ?: [];
    
    $settings = [
        'company_name' => $business['business_name'] ?? 'CLASSIC CAR CARE',
        'company_address' => $business['address'] ?? '111-J, SALEM MAIN ROAD, DHARMAPURI-636705',
        'company_phone' => $business['phone'] ?? '9943701430, 8489755755',
        'company_email' => '',
        'company_website' => '',
        'gst_number' => $business['gstin'] ?? '33AKDPY5436F1Z2',
        'pan_number' => '',
        'logo_path' => '',
        'qr_code_path' => '',
        'qr_code_data' => '',
        'invoice_terms' => "1. Goods Once Sold will not be taken back or exchanged.\n2. Seller is not responsible for any loss or damage of goods in transit\n3. Buyer Undertake to submit prescribed S.T.dech., to the seller on demand\n4. Dispute if any will be subject to Chennai Court jurisdiction Only.\n5. Certified that the particulars given above are true and correct",
        'invoice_footer' => 'Thank you for your business! Visit Again.',
        'invoice_prefix' => 'INV'
    ];
}

// Handle delete requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_logo'])) {
    $shop_id = $_POST['delete_shop_id'] ?? null;
    if ($shop_id == 0) $shop_id = null;
    
    // Get current logo path to delete file
    $select_sql = "SELECT logo_path FROM invoice_settings WHERE business_id = ? AND " . ($shop_id ? "shop_id = ?" : "shop_id IS NULL");
    $select_stmt = $pdo->prepare($select_sql);
    
    if ($shop_id) {
        $select_stmt->execute([$business_id, $shop_id]);
    } else {
        $select_stmt->execute([$business_id]);
    }
    
    $logo_data = $select_stmt->fetch();
    
    if (!empty($logo_data['logo_path']) && file_exists($logo_data['logo_path'])) {
        unlink($logo_data['logo_path']);
    }
    
    $sql = "UPDATE invoice_settings SET logo_path = NULL, updated_at = NOW() 
            WHERE business_id = ? AND " . ($shop_id ? "shop_id = ?" : "shop_id IS NULL");
    $stmt = $pdo->prepare($sql);
    
    if ($shop_id) {
        $stmt->execute([$business_id, $shop_id]);
    } else {
        $stmt->execute([$business_id]);
    }
    
    header('Location: invoice-settings.php?shop_id=' . ($shop_id ?? 0) . '&msg=' . urlencode('Logo deleted successfully!') . '&type=success');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_qr'])) {
    $shop_id = $_POST['delete_shop_id'] ?? null;
    if ($shop_id == 0) $shop_id = null;
    
    // Get current QR path to delete file
    $select_sql = "SELECT qr_code_path FROM invoice_settings WHERE business_id = ? AND " . ($shop_id ? "shop_id = ?" : "shop_id IS NULL");
    $select_stmt = $pdo->prepare($select_sql);
    
    if ($shop_id) {
        $select_stmt->execute([$business_id, $shop_id]);
    } else {
        $select_stmt->execute([$business_id]);
    }
    
    $qr_data = $select_stmt->fetch();
    
    if (!empty($qr_data['qr_code_path']) && file_exists($qr_data['qr_code_path'])) {
        unlink($qr_data['qr_code_path']);
    }
    
    $sql = "UPDATE invoice_settings SET qr_code_path = NULL, updated_at = NOW() 
            WHERE business_id = ? AND " . ($shop_id ? "shop_id = ?" : "shop_id IS NULL");
    $stmt = $pdo->prepare($sql);
    
    if ($shop_id) {
        $stmt->execute([$business_id, $shop_id]);
    } else {
        $stmt->execute([$business_id]);
    }
    
    header('Location: invoice-settings.php?shop_id=' . ($shop_id ?? 0) . '&msg=' . urlencode('QR Code deleted successfully!') . '&type=success');
    exit;
}

// Check for message in URL
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $message_type = $_GET['type'] ?? 'success';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bill Settings - Vehicle Service Center</title>
    <?php include('includes/head.php'); ?>
    <style>
        .preview-section {
            border: 1px solid #dee2e6;
            padding: 30px;
            margin-bottom: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        /* Align logo and QR code vertically centered */
        .preview-section .row {
            align-items: center;
        }
        
        /* Add left margin/padding to the middle text section */
        .preview-section .col-md-8 {
            padding-left: 40px;
        }
        
        /* Optional: Add subtle left border for better visual separation */
        .preview-section .col-md-8 {
            border-left: 1px solid #e0e0e0;
            margin-left: 20px;
        }
        
        /* Responsive adjustment for smaller screens */
        @media (max-width: 768px) {
            .preview-section .col-md-8 {
                padding-left: 15px;
                border-left: none;
                margin-left: 0;
                margin-top: 20px;
                border-top: 1px solid #e0e0e0;
                padding-top: 20px;
            }
            
            .preview-section .col-md-2 {
                text-align: center;
            }
        }
        
        /* Improve text spacing in preview */
        .preview-section h4 {
            margin-bottom: 12px;
            font-weight: 600;
        }
        
        .preview-section p {
            margin-bottom: 8px;
            line-height: 1.5;
        }
        
        .logo-preview, .qr-preview {
            max-width: 200px;
            max-height: 200px;
            border: 1px solid #dee2e6;
            padding: 5px;
            background: white;
            object-fit: contain;
            border-radius: 5px;
        }
        
        .file-upload-area {
            border: 2px dashed #007bff;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        .file-upload-area:hover {
            background: #f8f9fa;
        }
        .settings-nav {
            background: #343a40;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .settings-nav a {
            color: white;
            margin-right: 15px;
            text-decoration: none;
        }
        .settings-nav a:hover {
            color: #ddd;
        }
        .delete-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .delete-btn:hover {
            background: rgb(200, 35, 51);
            transform: scale(1.1);
        }
        .preview-container {
            position: relative;
            display: inline-block;
        }
        .image-info {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
        .shop-selector {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        .shop-tab {
            display: inline-block;
            padding: 8px 15px;
            margin-right: 10px;
            background: #e9ecef;
            border-radius: 4px;
            text-decoration: none;
            color: #495057;
            font-weight: 500;
        }
        .shop-tab.active {
            background: #007bff;
            color: white;
        }
        .shop-tab:hover {
            background: #dee2e6;
            text-decoration: none;
            color: #495057;
        }
        
        /* Bank Account Management Styles */
        .bank-account-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        .bank-account-card.default {
            border-left-color: #28a745;
            background-color: #f8fff9;
        }
        .bank-account-card.inactive {
            border-left-color: #dc3545;
            opacity: 0.7;
        }
        .bank-status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .account-actions {
            margin-top: 10px;
        }
        .account-number {
            font-family: monospace;
            background: #f8f9fa;
            padding: 2px 5px;
            border-radius: 3px;
        }
        .bank-account-item {
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .bank-account-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .bank-account-badges {
            display: flex;
            gap: 5px;
        }
        .bank-details-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 5px;
        }
        .bank-detail-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .bank-detail-item i {
            color: #6c757d;
        }
        
        .preview-container .delete-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body data-sidebar="dark">
<!-- Loader -->

<!-- Begin page -->
<div id="layout-wrapper">
    <?php include('includes/topbar.php'); ?>
    
    <!-- ========== Left Sidebar Start ========== -->
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>
    <!-- Left Sidebar End -->

    <!-- ============================================================== -->
    <!-- Start right Content here -->
    <!-- ============================================================== -->
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                
                <!-- Page Title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Invoice & Bill Settings</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Invoice Settings</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shop Selector -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="shop-selector">
                            <h5 class="mb-3">Select Shop/Store</h5>
                            <div>
                                <a href="?shop_id=0" class="shop-tab <?php echo (!isset($_GET['shop_id']) || $_GET['shop_id'] == 0) ? 'active' : ''; ?>">
                                    <i class="fas fa-building"></i> Business Default
                                </a>
                                <?php foreach ($shops as $shop): ?>
                                <a href="?shop_id=<?php echo $shop['id']; ?>" class="shop-tab <?php echo (isset($_GET['shop_id']) && $_GET['shop_id'] == $shop['id']) ? 'active' : ''; ?>">
                                    <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?> (<?php echo htmlspecialchars($shop['shop_code']); ?>)
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    Business Default settings apply to all shops unless overridden by shop-specific settings.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Message Display -->
                <?php if (!empty($message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Preview Section -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    Preview - 
                                    <?php if (!$selected_shop_id): ?>
                                        Business Default Settings
                                    <?php else: 
                                        $current_shop = null;
                                        foreach ($shops as $shop) {
                                            if ($shop['id'] == $selected_shop_id) {
                                                $current_shop = $shop;
                                                break;
                                            }
                                        }
                                    ?>
                                        <?php echo htmlspecialchars($current_shop['shop_name'] ?? 'Selected Shop'); ?> Settings
                                    <?php endif; ?>
                                </h5>
                                <div class="preview-section">
                                    <div class="row">
                                        <div class="col-md-2">
                                            <?php if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])): ?>
                                                <div class="preview-container">
                                                    <img src="<?php echo $settings['logo_path']; ?>" alt="Company Logo" class="logo-preview img-fluid">
                                                    <button type="button" class="delete-btn" onclick="deleteImage('logo', <?php echo $selected_shop_id ? $selected_shop_id : 0; ?>)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                                <div class="image-info">
                                                    Logo uploaded (<?php echo strtoupper(pathinfo($settings['logo_path'], PATHINFO_EXTENSION)); ?>)
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-warning">No logo uploaded</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-8">
                                            <h4><?php echo htmlspecialchars($settings['company_name']); ?></h4>
                                            <p class="mb-1"><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></p>
                                            <p class="mb-1">Phone: <?php echo htmlspecialchars($settings['company_phone']); ?></p>
                                            <?php if (!empty($settings['company_email'])): ?>
                                            <p class="mb-1">Email: <?php echo htmlspecialchars($settings['company_email']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($settings['company_website'])): ?>
                                            <p class="mb-1">Website: <?php echo htmlspecialchars($settings['company_website']); ?></p>
                                            <?php endif; ?>
                                            <p class="mb-1">GST: <?php echo htmlspecialchars($settings['gst_number']); ?></p>
                                            <?php if (!empty($settings['pan_number'])): ?>
                                            <p class="mb-1">PAN: <?php echo htmlspecialchars($settings['pan_number']); ?></p>
                                            <?php endif; ?>
                                            
                                            <!-- Bank Accounts in Preview -->
                                            <?php if (!empty($bank_accounts)): ?>
                                                <div class="mt-3 pt-3 border-top">
                                                    <h6 class="text-primary">Bank Accounts:</h6>
                                                    <?php 
                                                    $active_accounts = array_filter($bank_accounts, function($account) {
                                                        return $account['is_active'] == 1;
                                                    });
                                                    ?>
                                                    
                                                    <?php if (!empty($active_accounts)): ?>
                                                        <?php foreach ($active_accounts as $account): ?>
                                                        <div class="bank-detail-item">
                                                            <i class="fas fa-university"></i>
                                                            <strong><?php echo htmlspecialchars($account['bank_name']); ?>:</strong>
                                                            A/C: <?php echo htmlspecialchars($account['account_number']); ?>
                                                            <?php if ($account['account_holder_name']): ?>
                                                                (<?php echo htmlspecialchars($account['account_holder_name']); ?>)
                                                            <?php endif; ?>
                                                            <?php if ($account['is_default']): ?>
                                                                <span class="badge bg-success ms-2">Default</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <p class="text-muted">No active bank accounts</p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-2">
                                            <?php if (!empty($settings['qr_code_path']) && file_exists($settings['qr_code_path'])): ?>
                                                <div class="preview-container">
                                                    <img src="<?php echo $settings['qr_code_path']; ?>" alt="QR Code" class="qr-preview img-fluid">
                                                    <button type="button" class="delete-btn" onclick="deleteImage('qr', <?php echo $selected_shop_id ? $selected_shop_id : 0; ?>)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                                <div class="image-info">
                                                    QR Code uploaded (<?php echo strtoupper(pathinfo($settings['qr_code_path'], PATHINFO_EXTENSION)); ?>)
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-info">QR Code not uploaded</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="invoice-settings-form">
                    <!-- Hidden shop_id field -->
                    <input type="hidden" name="shop_id" value="<?php echo $selected_shop_id ? $selected_shop_id : 0; ?>">
                    
                    <!-- Company Information -->
                    <div class="row mb-4" id="company-info">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Company Information</h5>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Company Name *</label>
                                            <input type="text" class="form-control" name="company_name" 
                                                   value="<?php echo htmlspecialchars($settings['company_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Company Phone *</label>
                                            <input type="text" class="form-control" name="company_phone" 
                                                   value="<?php echo htmlspecialchars($settings['company_phone']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Company Email</label>
                                            <input type="email" class="form-control" name="company_email" 
                                                   value="<?php echo htmlspecialchars($settings['company_email']); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Website</label>
                                            <input type="text" class="form-control" name="company_website" 
                                                   value="<?php echo htmlspecialchars($settings['company_website']); ?>">
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label class="form-label">Company Address *</label>
                                            <textarea class="form-control" name="company_address" rows="3" required><?php echo htmlspecialchars($settings['company_address']); ?></textarea>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">GST Number *</label>
                                            <input type="text" class="form-control" name="gst_number" 
                                                   value="<?php echo htmlspecialchars($settings['gst_number']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">PAN Number</label>
                                            <input type="text" class="form-control" name="pan_number" 
                                                   value="<?php echo htmlspecialchars($settings['pan_number']); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Logo Upload -->
                    <div class="row mb-4" id="logo-upload">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Company Logo</h5>
                                    <input type="hidden" name="logo_shop_id" value="<?php echo $selected_shop_id ? $selected_shop_id : 0; ?>">
                                    <div class="file-upload-area" onclick="document.getElementById('logo-upload-input').click()">
                                        <i class="fas fa-cloud-upload-alt fa-2x mb-2"></i>
                                        <p>Click to upload company logo</p>
                                        <p class="text-muted small">Recommended size: 300x150px, Max size: 2MB</p>
                                        <p class="text-muted small">Supported formats: JPG, JPEG, PNG, GIF, WEBP</p>
                                        <p class="text-muted small">File will be saved with original extension</p>
                                    </div>
                                    <input type="file" id="logo-upload-input" name="company_logo" 
                                           accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" style="display: none;"
                                           onchange="previewImage(this, 'logo-preview-area')">
                                    
                                    <div id="logo-preview-area">
                                        <?php if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])): ?>
                                        <div class="mt-3">
                                            <p>Current Logo (<?php echo strtoupper(pathinfo($settings['logo_path'], PATHINFO_EXTENSION)); ?>):</p>
                                            <div class="preview-container">
                                                <img src="<?php echo $settings['logo_path']; ?>" alt="Current Logo" class="logo-preview">
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bank Details (Modified for Multiple Accounts) -->
                    <div class="row mb-4" id="bank-details">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="fas fa-university me-2"></i>
                                        Bank Accounts Management
                                        <button type="button" class="btn btn-primary btn-sm float-end" data-bs-toggle="modal" data-bs-target="#addBankAccountModal">
                                            <i class="fas fa-plus"></i> Add Bank Account
                                        </button>
                                    </h5>
                                    
                                    <?php if (empty($bank_accounts)): ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>
                                            No bank accounts added yet. Add your first bank account to display on invoices.
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBankAccountModal">
                                                    <i class="fas fa-plus"></i> Add First Bank Account
                                                </button>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="row">
                                            <?php foreach ($bank_accounts as $account): ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="card bank-account-card h-100 <?php echo $account['is_default'] ? 'default' : ''; ?> <?php echo !$account['is_active'] ? 'inactive' : ''; ?>">
                                                    <div class="card-body">
                                                        <div class="bank-status-badge">
                                                            <?php if ($account['is_default']): ?>
                                                                <span class="badge bg-success">Default</span>
                                                            <?php endif; ?>
                                                            <?php if (!$account['is_active']): ?>
                                                                <span class="badge bg-danger">Inactive</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <h6 class="card-title">
                                                            <i class="fas fa-bank me-2"></i>
                                                            <?php echo htmlspecialchars($account['bank_name']); ?>
                                                            <?php if ($account['account_type']): ?>
                                                                <small class="text-muted">(<?php echo htmlspecialchars($account['account_type']); ?>)</small>
                                                            <?php endif; ?>
                                                        </h6>
                                                        
                                                        <div class="mb-2">
                                                            <strong>Account Number:</strong>
                                                            <span class="account-number"><?php echo htmlspecialchars($account['account_number']); ?></span>
                                                        </div>
                                                        
                                                        <?php if ($account['account_holder_name']): ?>
                                                        <div class="mb-2">
                                                            <strong>Account Holder:</strong>
                                                            <?php echo htmlspecialchars($account['account_holder_name']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($account['ifsc_code']): ?>
                                                        <div class="mb-2">
                                                            <strong>IFSC Code:</strong>
                                                            <?php echo htmlspecialchars($account['ifsc_code']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($account['branch_name']): ?>
                                                        <div class="mb-2">
                                                            <strong>Branch:</strong>
                                                            <?php echo htmlspecialchars($account['branch_name']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($account['upi_id']): ?>
                                                        <div class="mb-2">
                                                            <strong>UPI ID:</strong>
                                                            <?php echo htmlspecialchars($account['upi_id']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="account-actions mt-3">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    data-bs-toggle="modal" data-bs-target="#editBankAccountModal"
                                                                    onclick="editBankAccount(<?php echo $account['id']; ?>)">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                            
                                                            <a href="?shop_id=<?php echo $selected_shop_id ? $selected_shop_id : 0; ?>&toggle_account=<?php echo $account['id']; ?>" 
                                                               class="btn btn-sm btn-outline-<?php echo $account['is_active'] ? 'warning' : 'success'; ?>">
                                                                <i class="fas fa-power-off"></i>
                                                                <?php echo $account['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                            </a>
                                                            
                                                            <a href="?shop_id=<?php echo $selected_shop_id ? $selected_shop_id : 0; ?>&delete_account=<?php echo $account['id']; ?>" 
                                                               class="btn btn-sm btn-outline-danger"
                                                               onclick="return confirm('Are you sure you want to delete this bank account?')">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            Only <strong>active</strong> bank accounts will appear on invoices. 
                                            The <strong>default</strong> account will be shown first on invoices if multiple active accounts exist.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- QR Code Settings -->
                    <div class="row mb-4" id="qr-code">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">QR Code Settings</h5>
                                    <input type="hidden" name="qr_shop_id" value="<?php echo $selected_shop_id ? $selected_shop_id : 0; ?>">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">QR Code Data (Optional - For reference)</label>
                                            <input type="text" class="form-control" name="qr_code_data" 
                                                   value="<?php echo htmlspecialchars($settings['qr_code_data']); ?>"
                                                   placeholder="UPI ID: yourname@upi or Payment Link">
                                            <small class="form-text text-muted">
                                                This is for reference only. Upload your QR code image below.
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <!-- QR Code Upload -->
                                    <div class="file-upload-area" onclick="document.getElementById('qr-upload-input').click()">
                                        <i class="fas fa-qrcode fa-2x mb-2"></i>
                                        <p>Click to upload QR Code image</p>
                                        <p class="text-muted small">Recommended size: 200x200px, Max size: 1MB</p>
                                        <p class="text-muted small">Supported formats: JPG, JPEG, PNG, GIF, WEBP</p>
                                        <p class="text-muted small">File will be saved with original extension</p>
                                    </div>
                                    <input type="file" id="qr-upload-input" name="qr_code_file" 
                                           accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" style="display: none;"
                                           onchange="previewImage(this, 'qr-preview-area')">
                                    
                                    <div id="qr-preview-area">
                                        <?php if (!empty($settings['qr_code_path']) && file_exists($settings['qr_code_path'])): ?>
                                        <div class="mt-3">
                                            <p>Current QR Code (<?php echo strtoupper(pathinfo($settings['qr_code_path'], PATHINFO_EXTENSION)); ?>):</p>
                                            <div class="preview-container">
                                                <img src="<?php echo $settings['qr_code_path']; ?>" alt="QR Code" class="qr-preview">
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Settings -->
                    <div class="row mb-4" id="invoice-settings">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Invoice Settings</h5>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Invoice Prefix</label>
                                            <input type="text" class="form-control" name="invoice_prefix" 
                                                   value="<?php echo htmlspecialchars($settings['invoice_prefix']); ?>">
                                            <small class="form-text text-muted">e.g., INV, BIL, etc.</small>
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label class="form-label">Invoice Terms & Conditions</label>
                                            <textarea class="form-control" name="invoice_terms" rows="5"><?php echo htmlspecialchars($settings['invoice_terms']); ?></textarea>
                                            <small class="form-text text-muted">Each line will be shown as a separate point in invoice.</small>
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label class="form-label">Invoice Footer Message</label>
                                            <textarea class="form-control" name="invoice_footer" rows="3"><?php echo htmlspecialchars($settings['invoice_footer']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-save"></i> Save Invoice Settings for 
                                        <?php if (!$selected_shop_id): ?>
                                            Business Default
                                        <?php else: 
                                            $current_shop = null;
                                            foreach ($shops as $shop) {
                                                if ($shop['id'] == $selected_shop_id) {
                                                    $current_shop = $shop;
                                                    break;
                                                }
                                            }
                                        ?>
                                            <?php echo htmlspecialchars($current_shop['shop_name'] ?? 'Selected Shop'); ?>
                                        <?php endif; ?>
                                    </button>
                                    
                                    <button type="button" class="btn btn-secondary btn-lg" onclick="resetForm()">
                                        <i class="fas fa-undo"></i> Reset Form
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </form>

            </div><!-- container-fluid -->
        </div><!-- End Page-content -->

        <?php include('includes/footer.php'); ?>
    </div><!-- end main content-->
</div><!-- END layout-wrapper -->

<!-- Add Bank Account Modal -->
<div class="modal fade" id="addBankAccountModal" tabindex="-1" aria-labelledby="addBankAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addBankAccountForm">
                <input type="hidden" name="shop_id" value="<?php echo $selected_shop_id ? $selected_shop_id : 0; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="addBankAccountModalLabel">
                        <i class="fas fa-plus-circle me-2"></i> Add New Bank Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Bank Name *</label>
                            <input type="text" class="form-control" name="bank_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Account Number *</label>
                            <input type="text" class="form-control" name="account_number" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Account Holder Name</label>
                            <input type="text" class="form-control" name="account_holder_name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">IFSC Code</label>
                            <input type="text" class="form-control" name="ifsc_code">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Branch Name</label>
                            <input type="text" class="form-control" name="branch_name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Account Type</label>
                            <select class="form-control" name="account_type">
                                <option value="">Select Type</option>
                                <option value="Savings">Savings Account</option>
                                <option value="Current">Current Account</option>
                                <option value="Salary">Salary Account</option>
                                <option value="Fixed Deposit">Fixed Deposit</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">UPI ID</label>
                            <input type="text" class="form-control" name="upi_id" placeholder="username@upi">
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                <label class="form-check-label" for="is_active">
                                    Active (Will appear on invoices)
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_default" id="is_default">
                                <label class="form-check-label" for="is_default">
                                    Set as Default Account
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_bank_account" class="btn btn-primary">Add Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Bank Account Modal -->
<div class="modal fade" id="editBankAccountModal" tabindex="-1" aria-labelledby="editBankAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editBankAccountForm">
                <input type="hidden" name="account_id" id="edit_account_id">
                <input type="hidden" name="shop_id" value="<?php echo $selected_shop_id ? $selected_shop_id : 0; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="editBankAccountModalLabel">
                        <i class="fas fa-edit me-2"></i> Edit Bank Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="editFormContent">
                        <!-- Dynamic content will be loaded here -->
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_bank_account" class="btn btn-primary">Update Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
    // File upload preview
    function previewImage(input, previewAreaId) {
        const file = input.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewArea = document.getElementById(previewAreaId);
                const fileExtension = file.name.split('.').pop().toUpperCase();
                previewArea.innerHTML = `
                    <div class="mt-3">
                        <p>New ${input.name.includes('logo') ? 'Logo' : 'QR Code'} Preview (${fileExtension}):</p>
                        <div class="preview-container">
                            <img src="${e.target.result}" class="${input.name.includes('logo') ? 'logo-preview' : 'qr-preview'} img-fluid">
                        </div>
                        <p class="text-muted small">${file.name} (${(file.size/1024).toFixed(2)} KB)</p>
                    </div>
                `;
            }
            reader.readAsDataURL(file);
        }
    }

    // Delete image function
    function deleteImage(type, shopId) {
        if (confirm(`Are you sure you want to delete the ${type === 'logo' ? 'logo' : 'QR code'}?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_' + type;
            input.value = '1';
            form.appendChild(input);
            
            // Add shop_id for deletion
            const shopInput = document.createElement('input');
            shopInput.type = 'hidden';
            shopInput.name = 'delete_shop_id';
            shopInput.value = shopId;
            form.appendChild(shopInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Reset form function
    function resetForm() {
        if (confirm('Are you sure you want to reset the form? All unsaved changes will be lost.')) {
            location.reload();
        }
    }

    // Function to edit bank account
    function editBankAccount(accountId) {
        // Fetch account details via AJAX
        $.ajax({
            url: 'get_bank_account.php',
            method: 'GET',
            data: { account_id: accountId },
            success: function(response) {
                $('#edit_account_id').val(accountId);
                $('#editFormContent').html(response);
            },
            error: function() {
                $('#editFormContent').html('<div class="alert alert-danger">Failed to load account details.</div>');
            }
        });
    }
</script>

</body>
</html>