<?php
// my-attendance.php
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

// Get user data
$user = null;
if ($logged_in_user_id) {
    try {
        $user_sql = "SELECT u.*, b.business_name 
                     FROM users u 
                     LEFT JOIN businesses b ON u.business_id = b.id 
                     WHERE u.id = ? AND u.business_id = ?";
        $user_stmt = $pdo->prepare($user_sql);
        $user_stmt->execute([$logged_in_user_id, $effective_business_id]);
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $_SESSION['message'] = "User not found or access denied";
            $_SESSION['message_type'] = 'error';
            header("Location: login.php");
            exit();
        }
        
        // Store business name in session
        if ($user && $user['business_name']) {
            $_SESSION['current_business_name'] = $user['business_name'];
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = "Error fetching user data";
        $_SESSION['message_type'] = 'error';
        header("Location: login.php");
        exit();
    }
}

// Get current date and time
$current_date = date('Y-m-d');
$current_month = date('Y-m');
$display_time = date('h:i A');

// Get filter parameters
$filter_month = $_GET['month'] ?? $current_month;
$filter_year = $_GET['year'] ?? date('Y');

// Validate month and year
if (!preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = $current_month;
}

// Get first day of the month
$first_day = date('N', strtotime($filter_month . '-01'));
$days_in_month = date('t', strtotime($filter_month . '-01'));

// Previous and next month navigation
$prev_month = date('Y-m', strtotime($filter_month . '-01 -1 month'));
$next_month = date('Y-m', strtotime($filter_month . '-01 +1 month'));

// Get attendance data for current user
$monthly_attendance = [];
$attendance_by_date = [];
$monthly_summary = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'half_day' => 0,
    'total_days' => 0,
    'total_working_hours' => 0,
    'avg_working_hours' => 0
];

try {
    $start_date = $filter_month . '-01';
    $end_date = $filter_month . '-' . $days_in_month;
    
    $attendance_sql = "SELECT * FROM attendance 
                      WHERE employee_id = ? 
                      AND business_id = ?
                      AND attendance_date BETWEEN ? AND ?
                      ORDER BY attendance_date";
    $attendance_stmt = $pdo->prepare($attendance_sql);
    $attendance_stmt->execute([$logged_in_user_id, $effective_business_id, $start_date, $end_date]);
    $monthly_attendance = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create associative array
    foreach ($monthly_attendance as $record) {
        $attendance_by_date[$record['attendance_date']] = $record;
        
        // Calculate working hours for each day
        if ($record['check_in'] && $record['check_out']) {
            $start = strtotime($record['check_in']);
            $end = strtotime($record['check_out']);
            $diff = $end - $start;
            $hours = $diff / 3600;
            $monthly_summary['total_working_hours'] += $hours;
        }
    }
    
    // Calculate summary
    foreach ($monthly_attendance as $record) {
        if ($record['status'] == 'present') $monthly_summary['present']++;
        if ($record['status'] == 'late') $monthly_summary['late']++;
        if ($record['status'] == 'absent') $monthly_summary['absent']++;
        if ($record['status'] == 'half_day') $monthly_summary['half_day']++;
        $monthly_summary['total_days']++;
    }
    
    // Calculate average working hours
    if ($monthly_summary['total_days'] > 0) {
        $monthly_summary['avg_working_hours'] = 
            round($monthly_summary['total_working_hours'] / $monthly_summary['total_days'], 2);
    }
    
} catch (PDOException $e) {
    $attendance_by_date = [];
    error_log("Attendance query error: " . $e->getMessage());
}

// Calculate attendance rate
$attendance_rate = $monthly_summary['total_days'] > 0 ? 
    (($monthly_summary['present'] + $monthly_summary['late'] + $monthly_summary['half_day']) / $monthly_summary['total_days']) * 100 : 0;

// Get today's attendance
$today_attendance = null;
try {
    $today_sql = "SELECT * FROM attendance WHERE employee_id = ? AND business_id = ? AND attendance_date = ?";
    $today_stmt = $pdo->prepare($today_sql);
    $today_stmt->execute([$logged_in_user_id, $effective_business_id, $current_date]);
    $today_attendance = $today_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $today_attendance = null;
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
    <title>My Attendance - <?php echo htmlspecialchars($user['business_name'] ?? 'Business'); ?></title>
   <?php
$page_title = "My Attendance";
include 'includes/head.php';
?>
    <style>
        :root {
            --primary-color: #4e73df;
            --success-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
            --info-color: #36b9cc;
            --dark-color: #5a5c69;
            --light-color: #f8f9fc;
        }
        
        .card-hover {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 4px solid;
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15) !important;
        }
        
        .card-hover.border-primary { border-left-color: var(--primary-color) !important; }
        .card-hover.border-success { border-left-color: var(--success-color) !important; }
        .card-hover.border-warning { border-left-color: var(--warning-color) !important; }
        .card-hover.border-danger { border-left-color: var(--danger-color) !important; }
        .card-hover.border-info { border-left-color: var(--info-color) !important; }
        
        .avatar-sm {
            width: 48px;
            height: 48px;
        }
        
        .avatar-title {
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .badge.bg-opacity-10 {
            opacity: 0.9;
        }
        
        .calendar-day {
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            padding: 10px;
            min-height: 100px;
            position: relative;
            transition: all 0.3s ease;
            background: white;
        }
        
        .calendar-day:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .calendar-day.today {
            border: 2px solid var(--primary-color);
            background-color: #e3f2fd;
        }
        
        .calendar-day.weekend {
            background-color: #f8f9fa;
        }
        
        .day-number {
            font-size: 14px;
            font-weight: bold;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .attendance-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 12px;
            margin-top: 5px;
            display: inline-block;
        }
        
        .time-entry {
            font-size: 10px;
            margin-top: 2px;
            line-height: 1.2;
        }
        
        .time-in { color: var(--success-color); }
        .time-out { color: var(--danger-color); }
        
        .border-start {
            border-left-width: 4px !important;
        }
        
        .table th {
            font-weight: 600;
            background-color: var(--light-color);
        }
        
        .badge.rounded-pill {
            padding: 0.5rem 0.75rem;
        }
        
        .business-badge {
            font-size: 12px;
            padding: 4px 10px;
            background: linear-gradient(135deg, var(--primary-color) 0%, #3651d4 100%);
            color: white;
            border-radius: 20px;
        }
        
        .month-nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .month-nav-btn:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .calendar-day {
                min-height: 80px;
                padding: 5px;
            }
            
            .day-number {
                font-size: 12px;
            }
            
            .attendance-badge {
                font-size: 9px;
                padding: 2px 5px;
            }
            
            .time-entry {
                font-size: 8px;
            }
            
            .avatar-sm {
                width: 40px;
                height: 40px;
            }
            
            .card-hover .card-body {
                padding: 1rem !important;
            }
            
            .card-hover .card-body h3 {
                font-size: 1.5rem;
            }
            
            .card-hover .card-body h6 {
                font-size: 0.85rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                margin-bottom: 5px;
            }
            
            .d-flex.align-items-center {
                flex-wrap: wrap;
            }
            
            .text-end {
                text-align: left !important;
                margin-top: 10px;
            }
        }
        
        @media (max-width: 576px) {
            .page-title-box {
                flex-direction: column;
                align-items: flex-start !important;
            }
            
            .page-title-box .d-flex {
                margin-top: 10px;
                width: 100%;
                justify-content: space-between;
            }
            
            .calendar-day {
                min-height: 70px;
            }
            
            .col {
                padding-left: 3px;
                padding-right: 3px;
            }
            
            .row.mb-2 {
                margin-bottom: 5px !important;
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
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="page-title-box d-flex align-items-center justify-content-between">
                                <h4 class="mb-0">
                                    <i class="bx bx-calendar-check me-2"></i> My Attendance
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i> 
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <div class="d-flex gap-2">
                                    <a href="attendance.php" class="btn btn-outline-primary">
                                        <i class="bx bx-log-in me-1"></i> Punch In/Out
                                    </a>
                                    <?php if (in_array($user_role, ['admin', 'shop_manager'])): ?>
                                        <a href="employee-attendance.php?business_id=<?php echo $effective_business_id; ?>" class="btn btn-outline-secondary">
                                            <i class="bx bx-user me-1"></i> Employee View
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Messages -->
                    <?php if ($message): ?>
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : $message_type; ?> alert-dismissible fade show" role="alert">
                                    <i class="bx bx-<?php echo $message_type == 'error' ? 'error-circle' : 'check-circle'; ?> me-2"></i>
                                    <?php echo htmlspecialchars($message); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Employee Information Card -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm card-hover border-primary">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <div class="avatar-title bg-light text-primary rounded-circle">
                                                        <i class="bx bx-user fs-4"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <h4 class="mb-1"><?= htmlspecialchars($user['full_name'] ?? 'User') ?></h4>
                                                    <p class="text-muted mb-0">
                                                        <i class="bx bx-user-circle me-1"></i> <?= ucfirst($user['role'] ?? '') ?> | 
                                                        <i class="bx bx-envelope me-1"></i> <?= htmlspecialchars($user['email'] ?? '') ?> | 
                                                        <i class="bx bx-phone me-1"></i> <?= htmlspecialchars($user['phone'] ?? 'N/A') ?>
                                                    </p>
                                                    <p class="mb-0">
                                                        <i class="bx bx-calendar me-1"></i>
                                                        Viewing: <strong><?= date('F Y', strtotime($filter_month . '-01')) ?></strong>
                                                        <span class="ms-2 text-primary">
                                                            <i class="bx bx-buildings me-1"></i>
                                                            <?= htmlspecialchars($user['business_name'] ?? 'Business') ?>
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            
                                            <?php if ($today_attendance): ?>
                                                <span class="badge bg-success rounded-pill px-3 py-1">
                                                    <i class="bx bx-check-circle me-1"></i> Marked Today
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning rounded-pill px-3 py-1">
                                                    <i class="bx bx-alarm-exclamation me-1"></i> Not Marked Today
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Attendance Summary -->
                    <?php if ($today_attendance): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm card-hover border-primary">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-calendar-day me-2"></i> Today's Attendance
                                    </h5>
                                    <div class="row">
                                        <div class="col-xl-3 col-md-6 mb-3">
                                            <div class="card card-hover border-success">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <h6 class="text-muted mb-1">Status</h6>
                                                            <?php 
                                                            $status_badge = [
                                                                'present' => 'success',
                                                                'late' => 'warning',
                                                                'absent' => 'danger',
                                                                'half_day' => 'info'
                                                            ][$today_attendance['status']] ?? 'secondary';
                                                            ?>
                                                            <h3 class="mb-0">
                                                                <span class="badge bg-<?= $status_badge ?> bg-opacity-10 text-<?= $status_badge ?> px-3 py-2">
                                                                    <?= ucfirst($today_attendance['status']) ?>
                                                                </span>
                                                            </h3>
                                                        </div>
                                                        <div class="avatar-sm">
                                                            <span class="avatar-title bg-<?= $status_badge ?> bg-opacity-10 text-<?= $status_badge ?> rounded-circle fs-3">
                                                                <i class="bx bx-calendar-check"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-xl-3 col-md-6 mb-3">
                                            <div class="card card-hover border-success">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <h6 class="text-muted mb-1">Check In</h6>
                                                            <h3 class="mb-0 text-success">
                                                                <?= $today_attendance['check_in'] ? date('h:i A', strtotime($today_attendance['check_in'])) : '--:--' ?>
                                                            </h3>
                                                        </div>
                                                        <div class="avatar-sm">
                                                            <span class="avatar-title bg-success bg-opacity-10 text-success rounded-circle fs-3">
                                                                <i class="bx bx-log-in"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-xl-3 col-md-6 mb-3">
                                            <div class="card card-hover border-danger">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <h6 class="text-muted mb-1">Check Out</h6>
                                                            <h3 class="mb-0 text-danger">
                                                                <?= $today_attendance['check_out'] ? date('h:i A', strtotime($today_attendance['check_out'])) : '--:--' ?>
                                                            </h3>
                                                        </div>
                                                        <div class="avatar-sm">
                                                            <span class="avatar-title bg-danger bg-opacity-10 text-danger rounded-circle fs-3">
                                                                <i class="bx bx-log-out"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-xl-3 col-md-6 mb-3">
                                            <div class="card card-hover border-info">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <h6 class="text-muted mb-1">Working Hours</h6>
                                                            <?php 
                                                            $working_hours = '--:--';
                                                            if ($today_attendance['check_in'] && $today_attendance['check_out']) {
                                                                $start = strtotime($today_attendance['check_in']);
                                                                $end = strtotime($today_attendance['check_out']);
                                                                $diff = $end - $start;
                                                                $hours = floor($diff / 3600);
                                                                $minutes = floor(($diff % 3600) / 60);
                                                                $working_hours = sprintf("%02d:%02d", $hours, $minutes);
                                                            }
                                                            ?>
                                                            <h3 class="mb-0 text-primary"><?= $working_hours ?></h3>
                                                        </div>
                                                        <div class="avatar-sm">
                                                            <span class="avatar-title bg-info bg-opacity-10 text-info rounded-circle fs-3">
                                                                <i class="bx bx-time"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($today_attendance['notes'])): ?>
                                    <div class="mt-4">
                                        <h6 class="mb-2"><i class="bx bx-note me-1"></i> Notes:</h6>
                                        <div class="alert alert-light">
                                            <?= nl2br(htmlspecialchars($today_attendance['notes'])) ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Monthly Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-3">
                            <div class="card card-hover border-primary">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Present Days</h6>
                                            <h3 class="mb-0 text-primary"><?= $monthly_summary['present'] ?></h3>
                                            <small class="text-muted">This Month</small>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-primary bg-opacity-10 text-primary rounded-circle fs-3">
                                                <i class="bx bx-check-circle"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-3">
                            <div class="card card-hover border-warning">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Late Days</h6>
                                            <h3 class="mb-0 text-warning"><?= $monthly_summary['late'] ?></h3>
                                            <small class="text-muted">This Month</small>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-warning bg-opacity-10 text-warning rounded-circle fs-3">
                                                <i class="bx bx-time-five"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-3">
                            <div class="card card-hover border-danger">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Absent Days</h6>
                                            <h3 class="mb-0 text-danger"><?= $monthly_summary['absent'] ?></h3>
                                            <small class="text-muted">This Month</small>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-danger bg-opacity-10 text-danger rounded-circle fs-3">
                                                <i class="bx bx-x-circle"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6 mb-3">
                            <div class="card card-hover border-info">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Attendance Rate</h6>
                                            <h3 class="mb-0 text-info"><?= number_format($attendance_rate, 1) ?>%</h3>
                                            <small class="text-muted">This Month</small>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-info bg-opacity-10 text-info rounded-circle fs-3">
                                                <i class="bx bx-line-chart"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Calendar Only -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h5 class="card-title mb-0">
                                            <i class="bx bx-calendar me-2"></i>
                                            <?= date('F Y', strtotime($filter_month . '-01')) ?> Calendar
                                        </h5>
                                        <div class="d-flex gap-2">
                                            <a href="my-attendance.php?month=<?= $prev_month ?>&business_id=<?= $effective_business_id ?>" 
                                               class="btn btn-outline-primary btn-sm">
                                                <i class="bx bx-chevron-left"></i> Prev
                                            </a>
                                            <a href="my-attendance.php?business_id=<?= $effective_business_id ?>" 
                                               class="btn btn-outline-secondary btn-sm">
                                                Current
                                            </a>
                                            <a href="my-attendance.php?month=<?= $next_month ?>&business_id=<?= $effective_business_id ?>" 
                                               class="btn btn-outline-primary btn-sm">
                                                Next <i class="bx bx-chevron-right"></i>
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <!-- Days Header -->
                                    <div class="row mb-3">
                                        <?php 
                                        $days_of_week = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                        foreach ($days_of_week as $day): 
                                        ?>
                                        <div class="col text-center fw-bold text-muted p-2">
                                            <?= $day ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- Calendar Days -->
                                    <?php 
                                    $day_counter = 1;
                                    for ($week = 1; $week <= 6; $week++): 
                                        if ($day_counter > $days_in_month) break;
                                    ?>
                                    <div class="row mb-2">
                                        <?php 
                                        for ($day_of_week = 1; $day_of_week <= 7; $day_of_week++): 
                                            if ($week == 1 && $day_of_week < $first_day) {
                                                // Blank days before first day of month
                                                echo '<div class="col mb-2"><div class="calendar-day bg-transparent border-0"></div></div>';
                                                continue;
                                            }
                                            
                                            if ($day_counter > $days_in_month) {
                                                // Blank days after last day of month
                                                echo '<div class="col mb-2"><div class="calendar-day bg-transparent border-0"></div></div>';
                                                continue;
                                            }
                                            
                                            $date = $filter_month . '-' . str_pad($day_counter, 2, '0', STR_PAD_LEFT);
                                            $attendance = $attendance_by_date[$date] ?? null;
                                            $is_today = ($date == $current_date);
                                            $is_weekend = ($day_of_week >= 6);
                                            
                                            // Determine status color
                                            if ($attendance) {
                                                $status_color = [
                                                    'present' => 'success',
                                                    'late' => 'warning',
                                                    'absent' => 'danger',
                                                    'half_day' => 'info'
                                                ][$attendance['status']] ?? 'secondary';
                                            } else {
                                                $status_color = 'secondary';
                                            }
                                        ?>
                                        <div class="col mb-2">
                                            <div class="calendar-day <?= $is_today ? 'today' : '' ?> <?= $is_weekend ? 'weekend' : '' ?>">
                                                <div class="day-number">
                                                    <?= $day_counter ?>
                                                    <?php if ($is_today): ?>
                                                        <span class="badge bg-primary badge-sm">Today</span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <?php if ($attendance): ?>
                                                    <div>
                                                        <span class="badge bg-<?= $status_color ?> attendance-badge">
                                                            <?= ucfirst($attendance['status']) ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <?php if ($attendance['check_in']): ?>
                                                    <div class="time-entry time-in">
                                                        <i class="bx bx-log-in"></i>
                                                        <?= date('h:i', strtotime($attendance['check_in'])) ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($attendance['check_out']): ?>
                                                    <div class="time-entry time-out">
                                                        <i class="bx bx-log-out"></i>
                                                        <?= date('h:i', strtotime($attendance['check_out'])) ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                <?php else: ?>
                                                    <div>
                                                        <span class="badge bg-secondary attendance-badge">
                                                            Not Marked
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php 
                                        $day_counter++;
                                        endfor; 
                                        ?>
                                    </div>
                                    <?php endfor; ?>
                                    
                                    <!-- Legend -->
                                    <div class="mt-4 pt-3 border-top">
                                        <h6 class="mb-3">Legend:</h6>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="badge bg-success me-2">Present</span>
                                            <span class="badge bg-warning me-2">Late</span>
                                            <span class="badge bg-danger me-2">Absent</span>
                                            <span class="badge bg-info me-2">Half Day</span>
                                            <span class="badge bg-secondary me-2">Not Marked</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Summary -->
                                    <div class="mt-4 p-3 bg-light rounded">
                                        <div class="row">
                                            <div class="col-md-3 mb-2">
                                                <small class="text-muted">Total Days:</small>
                                                <h5><?= $monthly_summary['total_days'] ?></h5>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <small class="text-muted">Attendance:</small>
                                                <h5 class="text-<?= $attendance_rate >= 90 ? 'success' : ($attendance_rate >= 70 ? 'warning' : 'danger') ?>">
                                                    <?= number_format($attendance_rate, 1) ?>%
                                                </h5>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <small class="text-muted">Total Hours:</small>
                                                <h5><?= number_format($monthly_summary['total_working_hours'], 1) ?> hrs</h5>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <small class="text-muted">Avg Hours:</small>
                                                <h5><?= number_format($monthly_summary['avg_working_hours'], 1) ?> hrs</h5>
                                            </div>
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

    <!-- JAVASCRIPT -->
    <?php include('includes/scripts.php') ?>

    <script>
    $(document).ready(function() {
        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);

        // Calendar day hover effect
        $('.calendar-day').hover(
            function() { $(this).css('box-shadow', '0 4px 12px rgba(0,0,0,0.15)'); },
            function() { $(this).css('box-shadow', 'none'); }
        );
    });
    </script>

    <style media="print">
        @media print {
            .vertical-menu, .topbar, .footer, 
            .page-title-box, .btn, .business-badge {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
            }
            
            .card {
                border: 1px solid #ddd !important;
                box-shadow: none !important;
                margin-bottom: 20px;
            }
            
            .card-body {
                padding: 15px !important;
            }
            
            .calendar-day {
                border: 1px solid #ddd !important;
                min-height: 70px !important;
            }
        }
    </style>

</body>
</html>