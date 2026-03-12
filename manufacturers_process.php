<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$business_id = $_SESSION['business_id'];

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    try {
        // Check if manufacturer has any purchases
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE manufacturer_id = ?");
        $check_stmt->execute([$id]);
        $count = $check_stmt->fetchColumn();
        
        if ($count > 0) {
            $_SESSION['error'] = "Cannot delete supplier. They have associated purchase records.";
        } else {
            // Delete contacts first
            $delete_contacts = $pdo->prepare("DELETE FROM manufacturer_contacts WHERE manufacturer_id = ?");
            $delete_contacts->execute([$id]);
            
            // Delete manufacturer
            $delete_stmt = $pdo->prepare("DELETE FROM manufacturers WHERE id = ? AND business_id = ?");
            $delete_stmt->execute([$id, $business_id]);
            
            if ($delete_stmt->rowCount() > 0) {
                $_SESSION['success'] = "Supplier deleted successfully.";
            } else {
                $_SESSION['error'] = "Supplier not found or you don't have permission to delete.";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
    
    header('Location: manufacturers.php');
    exit();
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gstin = trim($_POST['gstin'] ?? '');
    $account_holder_name = trim($_POST['account_holder_name'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $ifsc_code = trim($_POST['ifsc_code'] ?? '');
    $branch_name = trim($_POST['branch_name'] ?? '');
    $upi_id = trim($_POST['upi_id'] ?? '');
    $shop_id = !empty($_POST['shop_id']) ? $_POST['shop_id'] : null;
    $outstanding_type = $_POST['initial_outstanding_type'] ?? 'none';
    $outstanding_amount = !empty($_POST['initial_outstanding_amount']) ? floatval($_POST['initial_outstanding_amount']) : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Handle UPI QR Code upload
    $upi_qr_code = null;
    
    // Check if this is an edit and we have existing QR code
    if (!empty($_POST['id'])) {
        $stmt = $pdo->prepare("SELECT upi_qr_code FROM manufacturers WHERE id = ? AND business_id = ?");
        $stmt->execute([$_POST['id'], $business_id]);
        $existing = $stmt->fetch();
        $existing_qr = $existing ? $existing['upi_qr_code'] : null;
    } else {
        $existing_qr = null;
    }
    
    // Handle new QR code upload
    if (isset($_FILES['upi_qr_code']) && $_FILES['upi_qr_code']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['upi_qr_code']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $upload_dir = 'uploads/upi_qr_codes/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_filename = 'upi_' . time() . '_' . uniqid() . '.' . $ext;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['upi_qr_code']['tmp_name'], $upload_path)) {
                $upi_qr_code = $upload_path;
                
                // Delete old QR code if exists
                if ($existing_qr && file_exists($existing_qr)) {
                    unlink($existing_qr);
                }
            }
        } else {
            $_SESSION['error'] = "Invalid file type. Please upload JPG, PNG, or GIF.";
            $_SESSION['form_data'] = $_POST;
            header('Location: manufacturers.php');
            exit();
        }
    }
    
    // If remove QR code is checked
    if (isset($_POST['remove_qr_code']) && $_POST['remove_qr_code'] == '1') {
        if ($existing_qr && file_exists($existing_qr)) {
            unlink($existing_qr);
        }
        $upi_qr_code = null;
    } elseif (!$upi_qr_code && $existing_qr) {
        // Keep existing QR code
        $upi_qr_code = $existing_qr;
    }
    
    // Validation
    if (empty($name)) {
        $_SESSION['error'] = "Supplier name is required.";
        $_SESSION['form_data'] = $_POST;
        header('Location: manufacturers.php');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        if (empty($_POST['id'])) {
            // Insert new manufacturer
            $sql = "INSERT INTO manufacturers (business_id, shop_id, name, phone, email, address, gstin, 
                    account_holder_name, bank_name, account_number, ifsc_code, branch_name, upi_id, upi_qr_code,
                    initial_outstanding_type, initial_outstanding_amount, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $business_id, $shop_id, $name, $phone, $email, $address, $gstin,
                $account_holder_name, $bank_name, $account_number, $ifsc_code, $branch_name, $upi_id, $upi_qr_code,
                $outstanding_type, $outstanding_amount, $is_active
            ]);
            
            $manufacturer_id = $pdo->lastInsertId();
            $action = 'added';
            
            // Create outstanding history entry if there's initial outstanding
            if ($outstanding_type !== 'none' && $outstanding_amount > 0) {
                $history_sql = "INSERT INTO manufacturer_outstanding_history 
                               (manufacturer_id, date, type, amount, balance_after, reference_no, notes, created_by, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $history_stmt = $pdo->prepare($history_sql);
                
                // For initial outstanding, balance_after is the same as amount
                // For debit, balance_after is positive; for credit, balance_after is negative
                $balance_after = ($outstanding_type === 'debit') ? $outstanding_amount : -$outstanding_amount;
                
                $history_stmt->execute([
                    $manufacturer_id,
                    date('Y-m-d'), // Current date
                    $outstanding_type, // 'credit' or 'debit'
                    $outstanding_amount,
                    $balance_after,
                    null, // reference_no
                    'Initial outstanding balance',
                    $_SESSION['user_id']
                ]);
            }
            
        } else {
            // Update existing manufacturer
            $id = $_POST['id'];
            
            // Check if manufacturer belongs to this business
            $check_stmt = $pdo->prepare("SELECT id, initial_outstanding_type, initial_outstanding_amount FROM manufacturers WHERE id = ? AND business_id = ?");
            $check_stmt->execute([$id, $business_id]);
            $old_manufacturer = $check_stmt->fetch();
            
            if (!$old_manufacturer) {
                throw new Exception("Supplier not found or you don't have permission to edit.");
            }
            
            $sql = "UPDATE manufacturers SET 
                    shop_id = ?, name = ?, phone = ?, email = ?, address = ?, gstin = ?,
                    account_holder_name = ?, bank_name = ?, account_number = ?, ifsc_code = ?, branch_name = ?, upi_id = ?, upi_qr_code = ?,
                    initial_outstanding_type = ?, initial_outstanding_amount = ?, is_active = ? 
                    WHERE id = ? AND business_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $shop_id, $name, $phone, $email, $address, $gstin,
                $account_holder_name, $bank_name, $account_number, $ifsc_code, $branch_name, $upi_id, $upi_qr_code,
                $outstanding_type, $outstanding_amount, $is_active,
                $id, $business_id
            ]);
            
            $manufacturer_id = $id;
            $action = 'updated';
            
            // Check if outstanding has changed
            $old_type = $old_manufacturer['initial_outstanding_type'];
            $old_amount = (float)$old_manufacturer['initial_outstanding_amount'];
            
            if ($old_type !== $outstanding_type || $old_amount != $outstanding_amount) {
                // Record the change in outstanding history
                if ($outstanding_type !== 'none' && $outstanding_amount > 0) {
                    $history_sql = "INSERT INTO manufacturer_outstanding_history 
                                   (manufacturer_id, date, type, amount, balance_after, reference_no, notes, created_by, created_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $history_stmt = $pdo->prepare($history_sql);
                    
                    $balance_after = ($outstanding_type === 'debit') ? $outstanding_amount : -$outstanding_amount;
                    
                    $history_stmt->execute([
                        $manufacturer_id,
                        date('Y-m-d'),
                        $outstanding_type,
                        $outstanding_amount,
                        $balance_after,
                        null,
                        'Outstanding balance updated from ' . ucfirst($old_type) . ': ₹' . number_format($old_amount, 2),
                        $_SESSION['user_id']
                    ]);
                } elseif ($old_type !== 'none' && $old_amount > 0 && ($outstanding_type === 'none' || $outstanding_amount == 0)) {
                    // Outstanding was removed
                    $history_sql = "INSERT INTO manufacturer_outstanding_history 
                                   (manufacturer_id, date, type, amount, balance_after, reference_no, notes, created_by, created_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $history_stmt = $pdo->prepare($history_sql);
                    
                    $history_stmt->execute([
                        $manufacturer_id,
                        date('Y-m-d'),
                        'payment_made', // Using payment_made to indicate outstanding cleared
                        $old_amount,
                        0,
                        null,
                        'Outstanding balance cleared',
                        $_SESSION['user_id']
                    ]);
                }
            }
        }
        
        // Handle contacts
        if (isset($_POST['contacts']) && is_array($_POST['contacts'])) {
            // Delete existing contacts if editing
            if (!empty($_POST['id'])) {
                $delete_contacts = $pdo->prepare("DELETE FROM manufacturer_contacts WHERE manufacturer_id = ?");
                $delete_contacts->execute([$manufacturer_id]);
            }
            
            // Insert new contacts
            $contact_sql = "INSERT INTO manufacturer_contacts 
                           (manufacturer_id, contact_person, designation, phone, mobile, email, is_primary) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
            $contact_stmt = $pdo->prepare($contact_sql);
            
            foreach ($_POST['contacts'] as $contact) {
                if (!empty($contact['contact_person'])) {
                    $is_primary = isset($contact['is_primary']) ? 1 : 0;
                    $contact_stmt->execute([
                        $manufacturer_id,
                        $contact['contact_person'] ?? '',
                        $contact['designation'] ?? '',
                        $contact['phone'] ?? '',
                        $contact['mobile'] ?? '',
                        $contact['email'] ?? '',
                        $is_primary
                    ]);
                }
            }
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Supplier $action successfully.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
        $_SESSION['form_data'] = $_POST;
    }
    
    header('Location: manufacturers.php');
    exit();
}

// If no valid action, redirect back
header('Location: manufacturers.php');
exit();
?>