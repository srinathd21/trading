<?php
// employee-attendance.php
require_once 'config/database.php';
date_default_timezone_set('Asia/Kolkata');

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get logged-in user ID, role and business
$logged_in_user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;
$business_id = $_SESSION['business_id'] ?? null;
$current_business_id = $_SESSION['current_business_id'] ?? null;
$effective_business_id = $current_business_id ?? $business_id;

// Check if user has permission
if (!$logged_in_user_id || !in_array($user_role, ['admin', 'shop_manager'])) {
    $_SESSION['message'] = "Access denied. Admin/Manager only.";
    $_SESSION['message_type'] = 'error';
    header("Location: login.php");
    exit();
}

// Get all active employees for current business
$employees = [];
try {
    $employees_sql = "SELECT id, full_name, username, role, email, phone FROM users 
                     WHERE is_active = 1 AND business_id = ? 
                     ORDER BY full_name";
    $employees_stmt = $pdo->prepare($employees_sql);
    $employees_stmt->execute([$effective_business_id]);
    $employees = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
}

// Get selected employee (default to logged-in user if not specified)
$selected_employee_id = $_GET['employee_id'] ?? $logged_in_user_id;
$selected_employee = null;

if ($selected_employee_id) {
    try {
        $emp_sql = "SELECT u.*, b.business_name FROM users u 
                   LEFT JOIN businesses b ON u.business_id = b.id 
                   WHERE u.id = ? AND u.business_id = ?";
        $emp_stmt = $pdo->prepare($emp_sql);
        $emp_stmt->execute([$selected_employee_id, $effective_business_id]);
        $selected_employee = $emp_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $selected_employee = null;
    }
}

// Get current month and year
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_year = $_GET['year'] ?? date('Y');

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $filter_month)) {
    $filter_month = date('Y-m');
}

// Get first day of the month
$first_day = date('N', strtotime($filter_month . '-01'));
$days_in_month = date('t', strtotime($filter_month . '-01'));
$current_date = date('Y-m-d');

// Previous and next month navigation
$prev_month = date('Y-m', strtotime($filter_month . '-01 -1 month'));
$next_month = date('Y-m', strtotime($filter_month . '-01 +1 month'));

// Get attendance data for selected employee
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

if ($selected_employee_id) {
    try {
        $start_date = $filter_month . '-01';
        $end_date = $filter_month . '-' . $days_in_month;
        
        $attendance_sql = "SELECT * FROM attendance 
                          WHERE employee_id = ? 
                          AND business_id = ?
                          AND attendance_date BETWEEN ? AND ?
                          ORDER BY attendance_date";
        $attendance_stmt = $pdo->prepare($attendance_sql);
        $attendance_stmt->execute([$selected_employee_id, $effective_business_id, $start_date, $end_date]);
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
    }
}

// Calculate attendance rate
$attendance_rate = $monthly_summary['total_days'] > 0 ? 
    (($monthly_summary['present'] + $monthly_summary['late'] + $monthly_summary['half_day']) / $monthly_summary['total_days']) * 100 : 0;

// Get today's attendance
$today_attendance = null;
if ($selected_employee_id) {
    try {
        $today_sql = "SELECT * FROM attendance WHERE employee_id = ? AND business_id = ? AND attendance_date = ?";
        $today_stmt = $pdo->prepare($today_sql);
        $today_stmt->execute([$selected_employee_id, $effective_business_id, $current_date]);
        $today_attendance = $today_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $today_attendance = null;
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
    <?php
    $page_title = "Employee Attendance";
    include 'includes/head.php';
    ?>
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
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
        
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px;
            padding-left: 12px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
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
            
            .select2-container {
                width: 100% !important;
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
                                    <i class="bx bx-user-check me-2"></i> Employee Attendance
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i> 
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <div class="d-flex gap-2">
                                    <a href="my-attendance.php" class="btn btn-outline-primary">
                                        <i class="bx bx-user me-1"></i> My Attendance
                                    </a>
                                    <a href="daily-attendance.php" class="btn btn-outline-secondary">
                                        <i class="bx bx-calendar me-1"></i> Daily Report
                                    </a>
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

                    <!-- Employee Selection Card -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm card-hover border-primary">
                                <div class="card-body">
                                    <h5 class="card-title mb-4">
                                        <i class="bx bx-user-search me-2"></i>
                                        Select Employee
                                    </h5>
                                    <form method="GET" id="employeeForm" class="row g-3">
                                        <div class="col-md-6">
                                            <label for="employeeSelect" class="form-label">Select Employee</label>
                                            <select id="employeeSelect" name="employee_id" class="form-select select2" required>
                                                <option value="">Choose Employee</option>
                                                <?php 
                                                foreach($employees as $emp): 
                                                ?>
                                                    <option value="<?= $emp['id'] ?>" 
                                                        <?= ($selected_employee_id == $emp['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($emp['full_name']) ?> 
                                                        (<?= htmlspecialchars($emp['role']) ?>)
                                                        - <?= htmlspecialchars($emp['username']) ?>
                                                    </option>
                                                <?php 
                                                endforeach; 
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="monthSelect" class="form-label">Month</label>
                                            <select id="monthSelect" name="month" class="form-select">
                                                <?php 
                                                for($m = 1; $m <= 12; $m++): 
                                                ?>
                                                    <option value="<?= date('Y-m', strtotime(date('Y') . '-' . $m . '-01')) ?>" 
                                                        <?= ($filter_month == date('Y-m', strtotime(date('Y') . '-' . $m . '-01'))) ? 'selected' : '' ?>>
                                                        <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                                    </option>
                                                <?php 
                                                endfor; 
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="yearSelect" class="form-label">Year</label>
                                            <select id="yearSelect" name="year" class="form-select">
                                                <?php 
                                                for($y = date('Y'); $y >= 2020; $y--): 
                                                ?>
                                                    <option value="<?= $y ?>" 
                                                        <?= ($filter_year == $y) ? 'selected' : '' ?>>
                                                        <?= $y ?>
                                                    </option>
                                                <?php 
                                                endfor; 
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-12 mt-3">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bx bx-search me-1"></i> View Attendance
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                                                <i class="bx bx-printer me-1"></i> Print Report
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($selected_employee): ?>
                    
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
                                                    <h4 class="mb-1"><?= htmlspecialchars($selected_employee['full_name']) ?></h4>
                                                    <p class="text-muted mb-0">
                                                        <i class="bx bx-user-circle me-1"></i> <?= ucfirst($selected_employee['role']) ?> | 
                                                        <i class="bx bx-envelope me-1"></i> <?= htmlspecialchars($selected_employee['email']) ?> | 
                                                        <i class="bx bx-phone me-1"></i> <?= htmlspecialchars($selected_employee['phone'] ?? 'N/A') ?>
                                                    </p>
                                                    <p class="mb-0">
                                                        <i class="bx bx-calendar me-1"></i>
                                                        Viewing: <strong><?= date('F Y', strtotime($filter_month . '-01')) ?></strong>
                                                        <span class="ms-2 text-primary">
                                                            <i class="bx bx-buildings me-1"></i>
                                                            <?= htmlspecialchars($selected_employee['business_name'] ?? 'Business') ?>
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="d-flex justify-content-end gap-2 mb-3 flex-wrap">
                                                <a href="employee-attendance.php?employee_id=<?= $selected_employee_id ?>&month=<?= $prev_month ?>&business_id=<?= $effective_business_id ?>" 
                                                   class="month-nav-btn" title="Previous Month">
                                                    <i class="bx bx-chevron-left"></i>
                                                </a>
                                                <a href="employee-attendance.php?employee_id=<?= $selected_employee_id ?>&business_id=<?= $effective_business_id ?>" 
                                                   class="btn btn-outline-primary btn-sm" title="Current Month">
                                                    <i class="bx bx-calendar"></i>
                                                </a>
                                                <a href="employee-attendance.php?employee_id=<?= $selected_employee_id ?>&month=<?= $next_month ?>&business_id=<?= $effective_business_id ?>" 
                                                   class="month-nav-btn" title="Next Month">
                                                    <i class="bx bx-chevron-right"></i>
                                                </a>
                                            </div>
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

                    <!-- Monthly Calendar -->
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
                                            <a href="employee-attendance.php?employee_id=<?= $selected_employee_id ?>&month=<?= $prev_month ?>&business_id=<?= $effective_business_id ?>" 
                                               class="btn btn-outline-primary btn-sm">
                                                <i class="bx bx-chevron-left"></i> Prev
                                            </a>
                                            <a href="employee-attendance.php?employee_id=<?= $selected_employee_id ?>&business_id=<?= $effective_business_id ?>" 
                                               class="btn btn-outline-secondary btn-sm">
                                                Current
                                            </a>
                                            <a href="employee-attendance.php?employee_id=<?= $selected_employee_id ?>&month=<?= $next_month ?>&business_id=<?= $effective_business_id ?>" 
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

                    <?php else: ?>
                    
                    <!-- No Employee Selected -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-body text-center py-5">
                                    <div class="avatar-lg mx-auto mb-4">
                                        <div class="avatar-title bg-light text-primary rounded-circle">
                                            <i class="bx bx-user-search fs-1"></i>
                                        </div>
                                    </div>
                                    <h4 class="mb-3">Select an Employee</h4>
                                    <p class="text-muted mb-4">Please select an employee from the dropdown to view their attendance records.</p>
                                    <p class="text-muted small">Currently showing your own attendance records.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php endif; ?>

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

    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2({
            placeholder: "Select an employee",
            allowClear: true,
            width: '100%'
        });
        
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
            .page-title-box, .btn, .business-badge,
            .select2-container {
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