<?php
// attendance.php
require_once 'config/database.php';
date_default_timezone_set('Asia/Kolkata');

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get logged-in user ID and role
$logged_in_user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;
$business_id = $_SESSION['business_id'] ?? null;
$current_business_id = $_SESSION['current_business_id'] ?? null; // Get current business from session

// Use current business ID if available, otherwise fall back to business_id
$effective_business_id = $current_business_id ?? $business_id;

// Check if user is logged in
if (!$logged_in_user_id) {
    $_SESSION['message'] = "Please login to access attendance panel";
    $_SESSION['message_type'] = 'error';
    header("Location: login.php");
    exit();
}

// Define allowed roles
$allowed_roles = ['admin', 'seller', 'staff', 'warehouse_manager', 'shop_manager', 'stock_manager', 'field_executive'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['message'] = "You don't have permission to access attendance panel";
    $_SESSION['message_type'] = 'error';
    header("Location: index.php");
    exit();
}

// Get current date and time
$current_date = date('Y-m-d');
$current_datetime = date('Y-m-d H:i:s');
$display_time = date('h:i A');

// Get user data
$user = null;
if ($logged_in_user_id) {
    try {
        $user_sql = "SELECT u.*, b.business_name 
                     FROM users u 
                     LEFT JOIN businesses b ON u.business_id = b.id 
                     WHERE u.id = ?";
        $user_stmt = $pdo->prepare($user_sql);
        $user_stmt->execute([$logged_in_user_id]);
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Store business name in session if not already set
        if ($user && $user['business_name'] && !isset($_SESSION['current_business_name'])) {
            $_SESSION['current_business_name'] = $user['business_name'];
        }
    } catch (PDOException $e) {
        $user = null;
    }
}

// Handle punch in/out actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['punch_action'])) {
        $action = $_POST['punch_action'];
        $user_id = $_POST['user_id'] ?? $logged_in_user_id;
        $notes = $_POST['notes'] ?? '';
        
        // Security check: User can only punch for themselves unless admin
        if ($user_role !== 'admin' && $user_id != $logged_in_user_id) {
            $_SESSION['message'] = "You can only punch attendance for yourself";
            $_SESSION['message_type'] = 'error';
            header("Location: attendance.php");
            exit();
        }
        
        try {
            // Check if attendance record exists for today
            $check_sql = "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? AND business_id = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$user_id, $current_date, $effective_business_id]);
            $existing_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($action === 'punch_in') {
                if ($existing_record && $existing_record['check_in']) {
                    $_SESSION['message'] = "You have already checked in today at " . date('h:i A', strtotime($existing_record['check_in']));
                    $_SESSION['message_type'] = 'warning';
                } else {
                    // Check if user is late (after 10:00 AM)
                    $late_threshold = strtotime('10:00:00');
                    $punch_time = strtotime(date('H:i:s'));
                    $status = ($punch_time > $late_threshold) ? 'late' : 'present';
                    
                    if ($existing_record) {
                        // Update existing record
                        $update_sql = "UPDATE attendance SET check_in = ?, status = ?, notes = CONCAT(IFNULL(notes, ''), ?) WHERE employee_id = ? AND attendance_date = ? AND business_id = ?";
                        $update_stmt = $pdo->prepare($update_sql);
                        $notes_text = $notes ? "\nCheck-in note: " . $notes : "";
                        $update_stmt->execute([$current_datetime, $status, $notes_text, $user_id, $current_date, $effective_business_id]);
                    } else {
                        // Create new record
                        $insert_sql = "INSERT INTO attendance (employee_id, business_id, attendance_date, status, check_in, notes) VALUES (?, ?, ?, ?, ?, ?)";
                        $insert_stmt = $pdo->prepare($insert_sql);
                        $insert_stmt->execute([$user_id, $effective_business_id, $current_date, $status, $current_datetime, $notes]);
                    }
                    
                    $_SESSION['message'] = "Check-in recorded successfully at " . date('h:i A');
                    if ($status == 'late') {
                        $_SESSION['message'] .= " (Late arrival)";
                    }
                    $_SESSION['message_type'] = 'success';
                }
                
            } elseif ($action === 'punch_out') {
                if (!$existing_record || !$existing_record['check_in']) {
                    $_SESSION['message'] = "Cannot check out without checking in first";
                    $_SESSION['message_type'] = 'error';
                } elseif ($existing_record['check_out']) {
                    $_SESSION['message'] = "You have already checked out today at " . date('h:i A', strtotime($existing_record['check_out']));
                    $_SESSION['message_type'] = 'warning';
                } else {
                    // Update check out time
                    $update_sql = "UPDATE attendance SET check_out = ?, notes = CONCAT(IFNULL(notes, ''), ?) WHERE employee_id = ? AND attendance_date = ? AND business_id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $notes_text = $notes ? "\nCheck-out note: " . $notes : "";
                    $update_stmt->execute([$current_datetime, $notes_text, $user_id, $current_date, $effective_business_id]);
                    
                    // Calculate working hours
                    if ($existing_record['check_in']) {
                        $start = strtotime($existing_record['check_in']);
                        $end = strtotime($current_datetime);
                        $diff = $end - $start;
                        $hours = floor($diff / 3600);
                        $minutes = floor(($diff % 3600) / 60);
                        $working_hours = sprintf("%d hours %02d minutes", $hours, $minutes);
                        
                        $_SESSION['message'] = "Check out recorded successfully at " . date('h:i A') . " (Worked: $working_hours)";
                        $_SESSION['message_type'] = 'success';
                    }
                }
            }
            
            header("Location: attendance.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['message'] = "Error processing attendance action: " . $e->getMessage();
            $_SESSION['message_type'] = 'error';
            header("Location: attendance.php");
            exit();
        }
    }
    
    // Handle regulation request
    if (isset($_POST['submit_regulation'])) {
        $date_missed = $_POST['date_missed'];
        $time_to_log = $_POST['time_to_log'];
        $reason = $_POST['reason'];
        $user_id = $_POST['user_id'] ?? $logged_in_user_id;
        
        // Security check
        if ($user_role !== 'admin' && $user_id != $logged_in_user_id) {
            $_SESSION['message'] = "You can only submit regulation for yourself";
            $_SESSION['message_type'] = 'error';
            header("Location: attendance.php");
            exit();
        }
        
        try {
            // Check if attendance already exists
            $check_sql = "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? AND business_id = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$user_id, $date_missed, $effective_business_id]);
            $existing_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_record && $existing_record['check_in']) {
                $_SESSION['message'] = "Attendance already recorded for " . date('d-m-Y', strtotime($date_missed));
                $_SESSION['message_type'] = 'warning';
            } else {
                // Store full datetime for regulation
                $check_in_datetime = $date_missed . ' ' . $time_to_log . ':00';
                $insert_sql = "INSERT INTO attendance (employee_id, business_id, attendance_date, status, check_in, notes) VALUES (?, ?, ?, 'present', ?, ?)";
                $insert_stmt = $pdo->prepare($insert_sql);
                $reason_note = "Regulation: " . $reason . " (Time: " . $time_to_log . ")";
                $insert_stmt->execute([$user_id, $effective_business_id, $date_missed, $check_in_datetime, $reason_note]);
                
                $_SESSION['message'] = "Regulation recorded successfully for " . date('d-m-Y', strtotime($date_missed));
                $_SESSION['message_type'] = 'success';
            }
            
            header("Location: attendance.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['message'] = "Error submitting regulation: " . $e->getMessage();
            $_SESSION['message_type'] = 'error';
            header("Location: attendance.php");
            exit(); 
        }
    }
    
    // Handle correction request
    if (isset($_POST['request_correction'])) {
        $attendance_date = $_POST['correction_date'];
        $correct_check_in = $_POST['correct_punch_in'];
        $correct_check_out = $_POST['correct_punch_out'];
        $reason = $_POST['correction_reason'];
        $user_id = $_POST['user_id'] ?? $logged_in_user_id;
        
        // Security check
        if ($user_role !== 'admin' && $user_id != $logged_in_user_id) {
            $_SESSION['message'] = "You can only request correction for yourself";
            $_SESSION['message_type'] = 'error';
            header("Location: attendance.php");
            exit();
        }
        
        try {
            // Get original attendance data
            $attendance_sql = "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? AND business_id = ?";
            $attendance_stmt = $pdo->prepare($attendance_sql);
            $attendance_stmt->execute([$user_id, $attendance_date, $effective_business_id]);
            $attendance_data = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Store full datetime for correction
            $check_in_datetime = $attendance_date . ' ' . $correct_check_in . ':00';
            $check_out_datetime = $attendance_date . ' ' . $correct_check_out . ':00';
            
            if (!$attendance_data) {
                // Create new record if doesn't exist
                $insert_sql = "INSERT INTO attendance (employee_id, business_id, attendance_date, status, check_in, check_out, notes) VALUES (?, ?, ?, 'present', ?, ?, ?)";
                $insert_stmt = $pdo->prepare($insert_sql);
                $reason_note = "Correction: " . $reason;
                $insert_stmt->execute([$user_id, $effective_business_id, $attendance_date, $check_in_datetime, $check_out_datetime, $reason_note]);
                
                $_SESSION['message'] = "Attendance created with correction for " . date('d-m-Y', strtotime($attendance_date));
                $_SESSION['message_type'] = 'success';
            } else {
                // Update attendance with correction
                $update_sql = "UPDATE attendance SET check_in = ?, check_out = ?, notes = CONCAT(IFNULL(notes, ''), ?) WHERE employee_id = ? AND attendance_date = ? AND business_id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $reason_note = "\nCorrection: " . $reason . " (Original: " . ($attendance_data['check_in'] ? date('h:i A', strtotime($attendance_data['check_in'])) : 'N/A') . " - " . ($attendance_data['check_out'] ? date('h:i A', strtotime($attendance_data['check_out'])) : 'N/A') . ")";
                $update_stmt->execute([$check_in_datetime, $check_out_datetime, $reason_note, $user_id, $attendance_date, $effective_business_id]);
                
                $_SESSION['message'] = "Attendance corrected successfully for " . date('d-m-Y', strtotime($attendance_date));
                $_SESSION['message_type'] = 'success';
            }
            
            header("Location: attendance.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['message'] = "Error submitting correction: " . $e->getMessage();
            $_SESSION['message_type'] = 'error';
            header("Location: attendance.php");
            exit();
        }
    }
}

// Get today's attendance for logged-in user
$today_attendance = null;
if ($logged_in_user_id && $effective_business_id) {
    try {
        $attendance_sql = "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? AND business_id = ?";
        $attendance_stmt = $pdo->prepare($attendance_sql);
        $attendance_stmt->execute([$logged_in_user_id, $current_date, $effective_business_id]);
        $today_attendance = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $today_attendance = null;
    }
}

// Get monthly attendance summary
$monthly_summary = [];
if ($logged_in_user_id && $effective_business_id) {
    try {
        $month_start = date('Y-m-01');
        $month_end = date('Y-m-t');
        
        $summary_sql = "SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days
            FROM attendance 
            WHERE employee_id = ? 
            AND business_id = ?
            AND attendance_date BETWEEN ? AND ?";
        
        $summary_stmt = $pdo->prepare($summary_sql);
        $summary_stmt->execute([$logged_in_user_id, $effective_business_id, $month_start, $month_end]);
        $monthly_summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $monthly_summary = [];
    }
}

// Get recent attendance history (last 7 days)
$attendance_history = [];
if ($logged_in_user_id && $effective_business_id) {
    try {
        $history_sql = "SELECT * FROM attendance WHERE employee_id = ? AND business_id = ? ORDER BY attendance_date DESC LIMIT 7";
        $history_stmt = $pdo->prepare($history_sql);
        $history_stmt->execute([$logged_in_user_id, $effective_business_id]);
        $attendance_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $attendance_history = [];
    }
}

// Display messages
$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Panel - <?php echo htmlspecialchars($_SESSION['current_business_name'] ?? 'Vidhya Traders'); ?></title>
    <?php include 'includes/head.php'; ?>
    
    <style>
        :root {
            --primary-color: #4e73df;
            --success-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
            --info-color: #36b9cc;
            --dark-color: #5a5c69;
        }
        
        .card-hover {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important;
        }
        
        .border-start {
            border-left-width: 4px !important;
        }
        
        .avatar-sm {
            width: 48px;
            height: 48px;
        }
        
        .avatar-title {
            font-size: 20px;
        }
        
        .badge.bg-opacity-10 {
            opacity: 0.9;
        }
        
        .time-display {
            font-size: 28px;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .nav-tabs-custom {
            border-bottom: 2px solid #dee2e6;
        }
        
        .nav-tabs-custom .nav-link {
            border: none;
            border-bottom: 2px solid transparent;
            color: #6c757d;
            font-weight: 500;
            padding: 0.75rem 1.5rem;
        }
        
        .nav-tabs-custom .nav-link.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            background-color: transparent;
        }
        
        .punch-card {
            border-radius: 12px;
            overflow: hidden;
            height: 100%;
            min-height: 250px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .punch-btn {
            padding: 15px 30px;
            font-size: 18px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .punch-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .punch-status {
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 20px;
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
            border-top: none;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state i {
            font-size: 3rem;
            opacity: 0.5;
            margin-bottom: 1rem;
        }
        
        .status-badge {
            font-size: 13px;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .page-title-box {
            margin-bottom: 1.5rem;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        
        .btn-group .btn {
            border-radius: 6px;
            margin: 0 2px;
        }
        
        @media (max-width: 768px) {
            .punch-btn {
                padding: 12px 20px;
                font-size: 16px;
            }
            
            .time-display {
                font-size: 24px;
            }
            
            .avatar-sm {
                width: 40px;
                height: 40px;
            }
        }
    </style>
</head>

<body data-sidebar="dark">

    <!-- Loader -->
    <?php include('includes/pre-loader.php') ?>

    <!-- Begin page -->
    <div id="layout-wrapper">

        <?php include('includes/topbar.php') ?>

        <!-- ========== Left Sidebar Start ========== -->
        <div class="vertical-menu">
            <div data-simplebar class="h-100">
                <?php include('includes/sidebar.php') ?>
            </div>
        </div>
        <!-- Left Sidebar End -->

        <!-- ============================================================== -->
        <!-- Start right Content here -->
        <!-- ============================================================== -->
        <div class="main-content">
            <div class="page-content">
                <div class="container-fluid">

                    <!-- Page Header -->
                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box d-flex align-items-center justify-content-between">
                                <h4 class="mb-0">
                                    <i class="bx bx-calendar-check me-2"></i> Attendance Management
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i> 
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <div class="d-flex gap-2">
                                    <?php if ($user_role === 'admin'): ?>
                                        <a href="daily-attendance.php?business_id=<?= $effective_business_id ?>" class="btn btn-outline-primary">
                                            <i class="bx bx-calendar me-1"></i> Daily Report
                                        </a>
                                        <a href="employee-attendance.php?business_id=<?= $effective_business_id ?>" class="btn btn-outline-secondary">
                                            <i class="bx bx-user me-1"></i> Employee View
                                        </a>
                                    <?php endif; ?>
                                    <a href="my-attendance.php?business_id=<?= $effective_business_id ?>" class="btn btn-primary">
                                        <i class="bx bx-history me-1"></i> My Attendance
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Display Messages -->
                    <?php if ($message): ?>
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : $message_type; ?> alert-dismissible fade show" role="alert">
                                    <i class="bx bx-<?php echo $message_type == 'success' ? 'check-circle' : ($message_type == 'error' ? 'error-circle' : 'info-circle'); ?> me-2"></i>
                                    <?php echo htmlspecialchars($message); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Welcome Card -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                                        <div>
                                            <h5 class="card-title mb-1">Welcome, <?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?>!</h5>
                                            <p class="text-muted mb-0">
                                                <i class="bx bx-calendar me-1"></i> <?php echo date('d F Y'); ?> | 
                                                <i class="bx bx-time me-1 ms-2"></i> <span id="liveTime"><?php echo $display_time; ?></span> | 
                                                <i class="bx bx-user-circle me-1 ms-2"></i> <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> |
                                                <i class="bx bx-buildings me-1 ms-2"></i> <?php echo htmlspecialchars($_SESSION['current_business_name'] ?? 'Business'); ?>
                                            </p>
                                        </div>
                                        <div class="alert alert-light alert-dismissible fade show mb-0" role="alert">
                                            <div class="d-flex align-items-center">
                                                <i class="bx bx-info-circle text-primary me-2"></i>
                                                <div>
                                                    <strong>Today's Status:</strong> 
                                                    <?php if ($today_attendance && $today_attendance['check_in']): ?>
                                                        <?php echo date('h:i A', strtotime($today_attendance['check_in'])); ?> - 
                                                        <?php echo $today_attendance['check_out'] ? date('h:i A', strtotime($today_attendance['check_out'])) : 'Not checked out'; ?>
                                                    <?php else: ?>
                                                        <span class="text-danger">Not checked in</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6">
                            <div class="card card-hover border-start border-primary border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Present Days</h6>
                                            <h3 class="mb-0 text-primary"><?php echo $monthly_summary['present_days'] ?? 0; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-check-circle text-primary"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <small class="text-muted">This Month</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card card-hover border-start border-warning border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Late Days</h6>
                                            <h3 class="mb-0 text-warning"><?php echo $monthly_summary['late_days'] ?? 0; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-time-five text-warning"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <small class="text-muted">This Month</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card card-hover border-start border-danger border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Absent Days</h6>
                                            <h3 class="mb-0 text-danger"><?php echo $monthly_summary['absent_days'] ?? 0; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-x-circle text-danger"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <small class="text-muted">This Month</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card card-hover border-start border-info border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Days</h6>
                                            <h3 class="mb-0 text-info"><?php echo $monthly_summary['total_days'] ?? 0; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-calendar text-info"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <small class="text-muted">This Month</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Punch In/Out Section -->
                    <div class="row mb-4">
                        <div class="col-lg-4 mb-4">
                            <div class="card shadow-sm <?php echo ($today_attendance && $today_attendance['check_in']) ? '' : 'border border-primary'; ?>">
                                <div class="card-body text-center">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-log-in-circle me-2"></i> Check In
                                    </h5>
                                    
                                    <?php if ($today_attendance && $today_attendance['check_in']): ?>
                                        <button class="btn btn-outline-success punch-btn w-100 mb-3" disabled>
                                            <i class="bx bx-check-circle me-2"></i> Already Checked In
                                        </button>
                                        <div class="time-display text-success">
                                            <?php echo date('h:i A', strtotime($today_attendance['check_in'])); ?>
                                        </div>
                                        <p class="text-muted mb-0">
                                            <small>Checked in <?php echo date('h:i A', strtotime($today_attendance['check_in'])); ?></small>
                                        </p>
                                    <?php else: ?>
                                        <button class="btn btn-success punch-btn w-100 mb-3" id="punchInBtn">
                                            <i class="bx bx-log-in-circle me-2"></i> Check In Now
                                        </button>
                                        <div class="time-display text-muted" id="currentTimeIn">
                                            <?php echo date('h:i A'); ?>
                                        </div>
                                        <p class="text-muted mb-0">
                                            <small>Click to check in for today</small>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="card shadow-sm <?php echo ($today_attendance && $today_attendance['check_out']) ? '' : 'border border-primary'; ?>">
                                <div class="card-body text-center">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-log-out-circle me-2"></i> Check Out
                                    </h5>
                                    
                                    <?php if ($today_attendance && $today_attendance['check_out']): ?>
                                        <button class="btn btn-outline-danger punch-btn w-100 mb-3" disabled>
                                            <i class="bx bx-check-circle me-2"></i> Already Checked Out
                                        </button>
                                        <div class="time-display text-danger">
                                            <?php echo date('h:i A', strtotime($today_attendance['check_out'])); ?>
                                        </div>
                                        <p class="text-muted mb-0">
                                            <small>Checked out <?php echo date('h:i A', strtotime($today_attendance['check_out'])); ?></small>
                                        </p>
                                    <?php elseif ($today_attendance && $today_attendance['check_in']): ?>
                                        <button class="btn btn-danger punch-btn w-100 mb-3" id="punchOutBtn">
                                            <i class="bx bx-log-out-circle me-2"></i> Check Out Now
                                        </button>
                                        <div class="time-display text-muted" id="currentTimeOut">
                                            <?php echo date('h:i A'); ?>
                                        </div>
                                        <p class="text-muted mb-0">
                                            <small>Click to check out</small>
                                        </p>
                                    <?php else: ?>
                                        <button class="btn btn-outline-secondary punch-btn w-100 mb-3" disabled>
                                            <i class="bx bx-log-out-circle me-2"></i> Check In First
                                        </button>
                                        <div class="time-display text-muted">--:--</div>
                                        <p class="text-muted mb-0">
                                            <small>Check in first to enable check out</small>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-stats me-2"></i> Today's Summary
                                    </h5>
                                    <div class="row">
                                        <div class="col-12 mb-3">
                                            <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                                <span class="text-muted">Status</span>
                                                <span>
                                                    <?php if ($today_attendance && $today_attendance['check_in']): ?>
                                                        <?php 
                                                        $status_class = [
                                                            'present' => 'success',
                                                            'late' => 'warning',
                                                            'absent' => 'danger'
                                                        ][$today_attendance['status']] ?? 'secondary';
                                                        ?>
                                                        <span class="badge bg-<?php echo $status_class; ?> bg-opacity-10 text-<?php echo $status_class; ?> px-3 py-2">
                                                            <i class="bx bx-circle me-1 fs-6"></i>
                                                            <?php echo ucfirst($today_attendance['status']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">
                                                            <i class="bx bx-circle me-1 fs-6"></i>
                                                            Not Marked
                                                        </span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="col-12 mb-3">
                                            <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                                <span class="text-muted">Working Hours</span>
                                                <span class="text-primary fw-bold">
                                                    <?php 
                                                    $today_working_hours = '--:--';
                                                    if ($today_attendance && $today_attendance['check_in'] && $today_attendance['check_out']) {
                                                        $start = strtotime($today_attendance['check_in']);
                                                        $end = strtotime($today_attendance['check_out']);
                                                        $diff = $end - $start;
                                                        $hours = floor($diff / 3600);
                                                        $minutes = floor(($diff % 3600) / 60);
                                                        $today_working_hours = sprintf("%02d:%02d", $hours, $minutes);
                                                    } elseif ($today_attendance && $today_attendance['check_in']) {
                                                        $start = strtotime($today_attendance['check_in']);
                                                        $now = time();
                                                        $diff = $now - $start;
                                                        $hours = floor($diff / 3600);
                                                        $minutes = floor(($diff % 3600) / 60);
                                                        $today_working_hours = sprintf("%02d:%02d", $hours, $minutes);
                                                    }
                                                    echo $today_working_hours;
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="col-12">
                                            <div class="alert alert-light border mb-0">
                                                <div class="d-flex">
                                                    <i class="bx bx-info-circle text-primary me-2 mt-1"></i>
                                                    <div>
                                                        <small class="text-muted">Office Hours: 9:00 AM - 6:00 PM</small><br>
                                                        <small class="text-muted">Late after: 10:00 AM</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent History and Request Forms -->
                    <div class="row">
                        <!-- Recent Attendance History -->
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title mb-3 d-flex justify-content-between align-items-center">
                                        <span>
                                            <i class="bx bx-history me-2"></i>
                                            Recent Attendance History
                                        </span>
                                        <a href="my-attendance.php?business_id=<?= $effective_business_id ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="bx bx-history me-1"></i> View All
                                        </a>
                                    </h5>
                                    
                                    <?php if (empty($attendance_history)): ?>
                                        <div class="empty-state">
                                            <i class="bx bx-history text-muted mb-3"></i>
                                            <h5>No Attendance History Found</h5>
                                            <p class="text-muted">Your recent attendance will appear here</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="text-center">Date</th>
                                                        <th class="text-center">Day</th>
                                                        <th class="text-center">Check In</th>
                                                        <th class="text-center">Check Out</th>
                                                        <th class="text-center">Status</th>
                                                        <th class="text-center">Hours</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($attendance_history as $record): 
                                                        $working_hours = '-';
                                                        if ($record['check_in'] && $record['check_out']) {
                                                            $start = strtotime($record['check_in']);
                                                            $end = strtotime($record['check_out']);
                                                            $diff = $end - $start;
                                                            $hours = floor($diff / 3600);
                                                            $minutes = floor(($diff % 3600) / 60);
                                                            $working_hours = sprintf("%02d:%02d", $hours, $minutes);
                                                        }
                                                        
                                                        $status_class = [
                                                            'present' => 'success',
                                                            'late'    => 'warning',
                                                            'absent'  => 'danger'
                                                        ][$record['status']] ?? 'secondary';
                                                    ?>
                                                        <tr>
                                                            <td class="text-center"><?php echo date('d/m/Y', strtotime($record['attendance_date'])); ?></td>
                                                            <td class="text-center"><?php echo date('D', strtotime($record['attendance_date'])); ?></td>
                                                            <td class="text-center <?php echo $record['check_in'] ? 'text-success' : 'text-muted'; ?>">
                                                                <?php echo $record['check_in'] ? date('h:i A', strtotime($record['check_in'])) : '-'; ?>
                                                            </td>
                                                            <td class="text-center <?php echo $record['check_out'] ? 'text-danger' : 'text-muted'; ?>">
                                                                <?php echo $record['check_out'] ? date('h:i A', strtotime($record['check_out'])) : '-'; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <span class="badge bg-<?php echo $status_class; ?> bg-opacity-10 text-<?php echo $status_class; ?> px-3 py-1">
                                                                    <?php echo ucfirst($record['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td class="text-center">
                                                                <strong class="text-primary"><?php echo $working_hours; ?></strong>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Request Forms -->
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <ul class="nav nav-tabs nav-tabs-custom nav-justified mb-3" role="tablist">
                                        <li class="nav-item">
                                            <a class="nav-link active" data-bs-toggle="tab" href="#regulation" role="tab">
                                                <i class="bx bx-time me-1"></i> Regulation
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" data-bs-toggle="tab" href="#correction" role="tab">
                                                <i class="bx bx-edit me-1"></i> Correction
                                            </a>
                                        </li>
                                    </ul>
                                    
                                    <div class="tab-content">
                                        <!-- Regulation Form -->
                                        <div class="tab-pane active" id="regulation" role="tabpanel">
                                            <h5 class="mb-3">Submit Regulation Request</h5>
                                            <p class="text-muted small mb-4">Record attendance for missed days.</p>
                                            <form method="POST" id="regulationForm">
                                                <input type="hidden" name="user_id" value="<?php echo $logged_in_user_id; ?>">
                                                
                                                <div class="mb-3">
                                                    <label for="date_missed" class="form-label">Date Missed <span class="text-danger">*</span></label>
                                                    <input type="date" class="form-control" id="date_missed" name="date_missed" 
                                                           max="<?php echo $current_date; ?>" value="<?php echo $current_date; ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="time_to_log" class="form-label">Time to Log <span class="text-danger">*</span></label>
                                                    <input type="time" class="form-control" id="time_to_log" name="time_to_log" 
                                                           value="<?php echo date('H:i'); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" id="reason" name="reason" 
                                                           placeholder="Brief reason for regulation" required>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-primary w-100" name="submit_regulation">
                                                    <i class="bx bx-send me-2"></i> Submit Regulation
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <!-- Correction Form -->
                                        <div class="tab-pane" id="correction" role="tabpanel">
                                            <h5 class="mb-3">Request Attendance Correction</h5>
                                            <p class="text-muted small mb-4">Correct existing attendance records.</p>
                                            <form method="POST" id="correctionForm">
                                                <input type="hidden" name="user_id" value="<?php echo $logged_in_user_id; ?>">
                                                
                                                <div class="mb-3">
                                                    <label for="correction_date" class="form-label">Date to Correct <span class="text-danger">*</span></label>
                                                    <input type="date" class="form-control" id="correction_date" name="correction_date" 
                                                           max="<?php echo $current_date; ?>" value="<?php echo $current_date; ?>" required>
                                                </div>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <div class="mb-3">
                                                            <label for="correct_punch_in" class="form-label">Check In <span class="text-danger">*</span></label>
                                                            <input type="time" class="form-control" id="correct_punch_in" name="correct_punch_in" 
                                                                   value="09:00" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="mb-3">
                                                            <label for="correct_punch_out" class="form-label">Check Out <span class="text-danger">*</span></label>
                                                            <input type="time" class="form-control" id="correct_punch_out" name="correct_punch_out" 
                                                                   value="18:00" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="correction_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" id="correction_reason" name="correction_reason" 
                                                           placeholder="Reason for correction" required>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-warning w-100" name="request_correction">
                                                    <i class="bx bx-edit me-2"></i> Request Correction
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- container-fluid -->
            </div>
            <!-- End Page-content -->

            <?php include('includes/footer.php') ?>
        </div>
        <!-- end main content-->

    </div>
    <!-- END layout-wrapper -->

    <!-- Right Sidebar -->
    <?php include('includes/rightbar.php') ?>
    <!-- /Right-bar -->

    <!-- Punch Modal -->
    <div class="modal fade" id="punchModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">
                        <i class="bx bx-log-in-circle me-2"></i> Check In
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="punchForm">
                    <input type="hidden" name="user_id" value="<?php echo $logged_in_user_id; ?>">
                    <input type="hidden" name="punch_action" id="punchAction">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Add any notes about this attendance..."></textarea>
                        </div>
                        <div class="alert alert-light mb-0">
                            <div class="d-flex align-items-center">
                                <i class="bx bx-time text-primary me-2"></i>
                                <div>
                                    <small class="text-muted">Current Time:</small>
                                    <div class="fw-bold text-primary" id="modalCurrentTime"><?php echo date('h:i:s A'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="confirmPunchBtn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT -->
    <?php include('includes/scripts.php') ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Live time update
        function updateLiveTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
            const liveTimeElement = document.getElementById('liveTime');
            const currentTimeIn = document.getElementById('currentTimeIn');
            const currentTimeOut = document.getElementById('currentTimeOut');
            const modalTimeElement = document.getElementById('modalCurrentTime');
            
            if (liveTimeElement) liveTimeElement.textContent = timeString;
            if (currentTimeIn) currentTimeIn.textContent = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            if (currentTimeOut) currentTimeOut.textContent = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            if (modalTimeElement) modalTimeElement.textContent = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
        }
        setInterval(updateLiveTime, 1000);
        
        // Punch In/Out Modal
        const punchModal = new bootstrap.Modal(document.getElementById('punchModal'));
        const punchInBtn = document.getElementById('punchInBtn');
        const punchOutBtn = document.getElementById('punchOutBtn');
        
        if (punchInBtn) {
            punchInBtn.addEventListener('click', function() {
                document.getElementById('modalTitle').innerHTML = '<i class="bx bx-log-in-circle me-2"></i> Check In';
                document.getElementById('punchAction').value = 'punch_in';
                document.getElementById('confirmPunchBtn').textContent = 'Confirm Check In';
                document.getElementById('confirmPunchBtn').className = 'btn btn-success';
                punchModal.show();
            });
        }
        
        if (punchOutBtn) {
            punchOutBtn.addEventListener('click', function() {
                document.getElementById('modalTitle').innerHTML = '<i class="bx bx-log-out-circle me-2"></i> Check Out';
                document.getElementById('punchAction').value = 'punch_out';
                document.getElementById('confirmPunchBtn').textContent = 'Confirm Check Out';
                document.getElementById('confirmPunchBtn').className = 'btn btn-danger';
                punchModal.show();
            });
        }
        
        // Form validation
        const regulationForm = document.getElementById('regulationForm');
        if (regulationForm) {
            regulationForm.addEventListener('submit', function(e) {
                const dateMissed = document.getElementById('date_missed').value;
                const timeToLog = document.getElementById('time_to_log').value;
                const reason = document.getElementById('reason').value;
                
                if (!dateMissed || !timeToLog || !reason) {
                    e.preventDefault();
                    alert('Please fill all required fields');
                    return false;
                }
                
                // Validate date is not in future
                const selectedDate = new Date(dateMissed);
                const today = new Date();
                today.setHours(0,0,0,0);
                
                if (selectedDate > today) {
                    e.preventDefault();
                    alert('Cannot submit regulation for future dates');
                    return false;
                }
            });
        }
        
        const correctionForm = document.getElementById('correctionForm');
        if (correctionForm) {
            correctionForm.addEventListener('submit', function(e) {
                const correctionDate = document.getElementById('correction_date').value;
                const punchIn = document.getElementById('correct_punch_in').value;
                const punchOut = document.getElementById('correct_punch_out').value;
                const reason = document.getElementById('correction_reason').value;
                
                if (!correctionDate || !punchIn || !punchOut || !reason) {
                    e.preventDefault();
                    alert('Please fill all required fields');
                    return false;
                }
                
                // Validate date is not in future
                const selectedDate = new Date(correctionDate);
                const today = new Date();
                today.setHours(0,0,0,0);
                
                if (selectedDate > today) {
                    e.preventDefault();
                    alert('Cannot request correction for future dates');
                    return false;
                }
                
                // Validate check out is after check in
                if (punchIn && punchOut) {
                    const inTime = new Date('2000-01-01T' + punchIn);
                    const outTime = new Date('2000-01-01T' + punchOut);
                    if (outTime <= inTime) {
                        e.preventDefault();
                        alert('Check out time must be after check in time');
                        return false;
                    }
                }
            });
        }
        
        // Auto-refresh page every 5 minutes
        setTimeout(() => {
            window.location.reload();
        }, 300000); // 5 minutes
        
        // Set today's date for date inputs
        const today = new Date().toISOString().split('T')[0];
        const dateMissedInput = document.getElementById('date_missed');
        const correctionDateInput = document.getElementById('correction_date');
        
        if (dateMissedInput) dateMissedInput.max = today;
        if (correctionDateInput) correctionDateInput.max = today;

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert:not(.alert-light)');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    </script>

</body>
</html>