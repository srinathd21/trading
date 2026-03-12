<?php
// daily-attendance.php
require_once 'config/database.php';
date_default_timezone_set('Asia/Kolkata');

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is admin or manager
$logged_in_user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;
$business_id = $_SESSION['business_id'] ?? 1;

// Define allowed roles for daily attendance
$allowed_roles = ['admin', 'shop_manager', 'warehouse_manager'];
if (!$logged_in_user_id || !in_array($user_role, $allowed_roles)) {
    $_SESSION['message'] = "Access denied. Manager or Admin only.";
    $_SESSION['message_type'] = 'error';
    header("Location: login.php");
    exit();
}

// Get date from query parameter or use today
$selected_date = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

// Get all active employees
$employees = [];
try {
    $employees_sql = "SELECT id, full_name, username, role FROM users WHERE business_id = ? AND is_active = 1 ORDER BY full_name";
    $employees_stmt = $pdo->prepare($employees_sql);
    $employees_stmt->execute([$business_id]);
    $employees = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
}

// Get attendance for selected date
$daily_attendance = [];
if (!empty($employees)) {
    $employee_ids = array_column($employees, 'id');
    $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
    
    try {
        $attendance_sql = "SELECT a.*, u.full_name, u.role 
                          FROM attendance a 
                          JOIN users u ON a.employee_id = u.id 
                          WHERE a.attendance_date = ? 
                          AND a.employee_id IN ($placeholders)
                          ORDER BY u.full_name";
        
        $params = array_merge([$selected_date], $employee_ids);
        $attendance_stmt = $pdo->prepare($attendance_sql);
        $attendance_stmt->execute($params);
        $daily_attendance = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create lookup array
        $attendance_by_employee = [];
        foreach ($daily_attendance as $record) {
            $attendance_by_employee[$record['employee_id']] = $record;
        }
        
    } catch (PDOException $e) {
        $attendance_by_employee = [];
    }
}

// Calculate daily summary
$daily_summary = [
    'total_employees' => count($employees),
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'not_marked' => 0,
    'checked_in' => 0,
    'checked_out' => 0
];

foreach ($employees as $employee) {
    $attendance = $attendance_by_employee[$employee['id']] ?? null;
    
    if ($attendance) {
        if ($attendance['status'] == 'present') $daily_summary['present']++;
        if ($attendance['status'] == 'late') $daily_summary['late']++;
        if ($attendance['status'] == 'absent') $daily_summary['absent']++;
        if ($attendance['check_in']) $daily_summary['checked_in']++;
        if ($attendance['check_out']) $daily_summary['checked_out']++;
    } else {
        $daily_summary['not_marked']++;
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
    <title>Daily Attendance Report</title>
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
        
        .date-navigation {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .date-navigation .btn {
            padding: 10px 20px;
            font-weight: 500;
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
            border-top: none;
        }
        
        .employee-row:hover {
            background-color: #f8f9fc;
        }
        
        .status-badge {
            font-size: 13px;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state i {
            font-size: 4rem;
            opacity: 0.5;
            margin-bottom: 1rem;
        }
        
        .page-title-box {
            margin-bottom: 1.5rem;
        }
        
        .btn-group .btn {
            border-radius: 6px;
            margin: 0 2px;
        }
        
        @media (max-width: 768px) {
            .avatar-sm {
                width: 40px;
                height: 40px;
            }
            
            .date-navigation .btn {
                padding: 8px 12px;
                font-size: 14px;
            }
            
            .stats-card h3 {
                font-size: 20px;
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
                                    <i class="bx bx-calendar me-2"></i> Daily Attendance Report
                                    <small class="text-muted ms-2">
                                        <i class="bx bx-buildings me-1"></i> 
                                        <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                    </small>
                                </h4>
                                <div class="d-flex gap-2">
                                    <a href="attendance.php" class="btn btn-outline-secondary">
                                        <i class="bx bx-arrow-back me-1"></i> Back to Panel
                                    </a>
                                    <button onclick="window.print()" class="btn btn-outline-primary">
                                        <i class="bx bx-printer me-1"></i> Print
                                    </button>
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

                    <!-- Date Navigation Card -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-3">
                                <i class="bx bx-calendar-check me-2"></i> Select Date
                            </h5>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                <a href="daily-attendance.php?date=<?php echo $prev_date; ?>" class="btn btn-outline-primary">
                                    <i class="bx bx-chevron-left me-1"></i> Previous Day
                                </a>
                                
                                <div class="text-center">
                                    <h4 class="mb-1 text-primary"><?php echo date('l, d F Y', strtotime($selected_date)); ?></h4>
                                    <form method="GET" class="mt-2">
                                        <input type="date" name="date" class="form-control form-control-sm" 
                                               value="<?php echo $selected_date; ?>" 
                                               max="<?php echo date('Y-m-d'); ?>"
                                               onchange="this.form.submit()" style="width: 150px;">
                                    </form>
                                </div>
                                
                                <a href="daily-attendance.php?date=<?php echo $next_date; ?>" 
                                   class="btn btn-outline-primary <?php echo $next_date > date('Y-m-d') ? 'disabled' : ''; ?>">
                                    Next Day <i class="bx bx-chevron-right ms-1"></i>
                                </a>
                            </div>
                            <div class="text-center mt-3">
                                <a href="daily-attendance.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="bx bx-calendar me-1"></i> Today
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-2 col-md-4 col-6 mb-3">
                            <div class="card card-hover border-start border-primary border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Employees</h6>
                                            <h3 class="mb-0 text-primary"><?php echo $daily_summary['total_employees']; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-user text-primary"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4 col-6 mb-3">
                            <div class="card card-hover border-start border-success border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Present</h6>
                                            <h3 class="mb-0 text-success"><?php echo $daily_summary['present']; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-check-circle text-success"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4 col-6 mb-3">
                            <div class="card card-hover border-start border-warning border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Late</h6>
                                            <h3 class="mb-0 text-warning"><?php echo $daily_summary['late']; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-time-five text-warning"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4 col-6 mb-3">
                            <div class="card card-hover border-start border-danger border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Absent</h6>
                                            <h3 class="mb-0 text-danger"><?php echo $daily_summary['absent']; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-x-circle text-danger"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4 col-6 mb-3">
                            <div class="card card-hover border-start border-info border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Checked In</h6>
                                            <h3 class="mb-0 text-info"><?php echo $daily_summary['checked_in']; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-log-in text-info"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-2 col-md-4 col-6 mb-3">
                            <div class="card card-hover border-start border-dark border-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Checked Out</h6>
                                            <h3 class="mb-0 text-dark"><?php echo $daily_summary['checked_out']; ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-dark bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-log-out text-dark"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Stats -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title mb-3">
                                        <i class="bx bx-stats me-2"></i> Attendance Summary
                                    </h5>
                                    <div class="row">
                                        <?php 
                                        $marked = $daily_summary['present'] + $daily_summary['late'] + $daily_summary['absent'];
                                        $rate = $daily_summary['total_employees'] > 0 ? ($marked / $daily_summary['total_employees']) * 100 : 0;
                                        $punctuality = $marked > 0 ? ($daily_summary['present'] / $marked) * 100 : 0;
                                        $checkin_rate = $daily_summary['total_employees'] > 0 ? ($daily_summary['checked_in'] / $daily_summary['total_employees']) * 100 : 0;
                                        ?>
                                        <div class="col-md-3 mb-3">
                                            <div class="text-center p-3 border rounded bg-light">
                                                <h6 class="text-muted mb-1">Attendance Rate</h6>
                                                <h3 class="mb-0 text-primary"><?php echo number_format($rate, 1); ?>%</h3>
                                                <small class="text-muted">
                                                    <?= $marked ?> of <?= $daily_summary['total_employees'] ?> marked
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3 mb-3">
                                            <div class="text-center p-3 border rounded bg-light">
                                                <h6 class="text-muted mb-1">Punctuality Rate</h6>
                                                <h3 class="mb-0 text-success"><?php echo number_format($punctuality, 1); ?>%</h3>
                                                <small class="text-muted">
                                                    <?= $daily_summary['present'] ?> of <?= $marked ?> on time
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3 mb-3">
                                            <div class="text-center p-3 border rounded bg-light">
                                                <h6 class="text-muted mb-1">Check-in Rate</h6>
                                                <h3 class="mb-0 text-info"><?php echo number_format($checkin_rate, 1); ?>%</h3>
                                                <small class="text-muted">
                                                    <?= $daily_summary['checked_in'] ?> of <?= $daily_summary['total_employees'] ?> checked in
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3 mb-3">
                                            <div class="text-center p-3 border rounded bg-light">
                                                <h6 class="text-muted mb-1">Not Marked</h6>
                                                <h3 class="mb-0 text-secondary"><?php echo $daily_summary['not_marked']; ?></h3>
                                                <small class="text-muted">Attendance not recorded</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Attendance Table -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <h5 class="card-title mb-3 d-flex justify-content-between align-items-center">
                                        <span>
                                            <i class="bx bx-user-check me-2"></i>
                                            Daily Attendance - <?php echo date('d/m/Y', strtotime($selected_date)); ?>
                                        </span>
                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2">
                                            <i class="bx bx-user me-1"></i> <?php echo $daily_summary['total_employees']; ?> Employees
                                        </span>
                                    </h5>
                                    
                                    <?php if (empty($employees)): ?>
                                        <div class="empty-state">
                                            <i class="bx bx-user-x text-muted mb-3"></i>
                                            <h5>No Employees Found</h5>
                                            <p class="text-muted">No employees are registered in the system</p>
                                            <a href="users.php" class="btn btn-primary">
                                                <i class="bx bx-plus me-1"></i> Add Employees
                                            </a>
                                        </div>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table id="attendanceTable" class="table table-hover align-middle w-100">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Employee</th>
                                                    <th class="text-center">Role</th>
                                                    <th class="text-center">Check In</th>
                                                    <th class="text-center">Check Out</th>
                                                    <th class="text-center">Working Hours</th>
                                                    <th class="text-center">Status</th>
                                                    <th class="text-center">Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($employees as $index => $employee): 
                                                    $attendance = $attendance_by_employee[$employee['id']] ?? null;
                                                    $working_hours = '-';
                                                    
                                                    if ($attendance && $attendance['check_in'] && $attendance['check_out']) {
                                                        $start = strtotime($attendance['check_in']);
                                                        $end = strtotime($attendance['check_out']);
                                                        $diff = $end - $start;
                                                        $hours = floor($diff / 3600);
                                                        $minutes = floor(($diff % 3600) / 60);
                                                        $working_hours = sprintf("%02d:%02d", $hours, $minutes);
                                                    }
                                                    
                                                    // Determine status
                                                    if ($attendance) {
                                                        $status_class = [
                                                            'present' => 'success',
                                                            'late' => 'warning',
                                                            'absent' => 'danger'
                                                        ][$attendance['status']] ?? 'secondary';
                                                        $status_text = ucfirst($attendance['status']);
                                                    } else {
                                                        $status_class = 'secondary';
                                                        $status_text = 'Not Marked';
                                                    }
                                                ?>
                                                <tr class="employee-row">
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar-sm me-3">
                                                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                                     style="width: 40px; height: 40px;">
                                                                    <i class="bx bx-user fs-4"></i>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <strong class="d-block mb-1"><?php echo htmlspecialchars($employee['full_name']); ?></strong>
                                                                <small class="text-muted">
                                                                    <i class="bx bx-at me-1"></i><?php echo htmlspecialchars($employee['username']); ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1">
                                                            <?php echo ucfirst(str_replace('_', ' ', $employee['role'])); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($attendance && $attendance['check_in']): ?>
                                                            <span class="text-success d-flex align-items-center justify-content-center">
                                                                <i class="bx bx-log-in-circle me-2"></i>
                                                                <?php echo date('h:i A', strtotime($attendance['check_in'])); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($attendance && $attendance['check_out']): ?>
                                                            <span class="text-danger d-flex align-items-center justify-content-center">
                                                                <i class="bx bx-log-out-circle me-2"></i>
                                                                <?php echo date('h:i A', strtotime($attendance['check_out'])); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <strong class="text-primary"><?php echo $working_hours; ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?php echo $status_class; ?> bg-opacity-10 text-<?php echo $status_class; ?> px-3 py-1">
                                                            <i class="bx bx-<?php echo $status_class == 'success' ? 'check-circle' : ($status_class == 'warning' ? 'time-five' : ($status_class == 'danger' ? 'x-circle' : 'circle')); ?> me-1"></i>
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <small class="text-muted">
                                                            <?php if ($attendance && !empty($attendance['notes'])): ?>
                                                                <button type="button" class="btn btn-sm btn-outline-info" 
                                                                        data-bs-toggle="tooltip" 
                                                                        title="<?php echo htmlspecialchars($attendance['notes']); ?>">
                                                                    <i class="bx bx-note"></i> View Note
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </small>
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

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#attendanceTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'asc']],
            dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
            language: {
                search: "Search employees:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ employees",
                infoFiltered: "(filtered from <?= $daily_summary['total_employees'] ?> total employees)",
                paginate: {
                    previous: "<i class='bx bx-chevron-left'></i>",
                    next: "<i class='bx bx-chevron-right'></i>"
                }
            }
        });

        // Tooltips
        $('[data-bs-toggle="tooltip"]').tooltip();

        // Row hover effect
        $('.employee-row').hover(
            function() { $(this).addClass('bg-light'); },
            function() { $(this).removeClass('bg-light'); }
        );

        // Auto-close alerts after 5 seconds
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    });

    // Print functionality
    function printReport() {
        const printWindow = window.open('', '_blank');
        const printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Daily Attendance Report - <?php echo date('d/m/Y', strtotime($selected_date)); ?></title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    h2 { color: #333; margin-bottom: 20px; }
                    h4 { color: #666; margin-bottom: 10px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f5f5f5; font-weight: bold; }
                    .text-success { color: #28a745; }
                    .text-danger { color: #dc3545; }
                    .text-warning { color: #ffc107; }
                    .text-info { color: #17a2b8; }
                    .summary { display: flex; justify-content: space-between; margin-bottom: 20px; }
                    .summary-box { flex: 1; padding: 10px; margin: 0 5px; text-align: center; }
                    .page-break { page-break-before: always; }
                    @media print {
                        body { margin: 0; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <h2>Daily Attendance Report</h2>
                <h4>Date: <?php echo date('l, d F Y', strtotime($selected_date)); ?></h4>
                <h4>Business: <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?></h4>
                
                <div class="summary no-print">
                    <div class="summary-box">
                        <h5>Total Employees</h5>
                        <h3><?php echo $daily_summary['total_employees']; ?></h3>
                    </div>
                    <div class="summary-box">
                        <h5>Present</h5>
                        <h3 class="text-success"><?php echo $daily_summary['present']; ?></h3>
                    </div>
                    <div class="summary-box">
                        <h5>Late</h5>
                        <h3 class="text-warning"><?php echo $daily_summary['late']; ?></h3>
                    </div>
                    <div class="summary-box">
                        <h5>Absent</h5>
                        <h3 class="text-danger"><?php echo $daily_summary['absent']; ?></h3>
                    </div>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Role</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Working Hours</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($employees as $index => $employee): 
                            $attendance = $attendance_by_employee[$employee['id']] ?? null;
                            $working_hours = '-';
                            
                            if ($attendance && $attendance['check_in'] && $attendance['check_out']) {
                                $start = strtotime($attendance['check_in']);
                                $end = strtotime($attendance['check_out']);
                                $diff = $end - $start;
                                $hours = floor($diff / 3600);
                                $minutes = floor(($diff % 3600) / 60);
                                $working_hours = sprintf("%02d:%02d", $hours, $minutes);
                            }
                            
                            if ($attendance) {
                                $status_class = [
                                    'present' => 'text-success',
                                    'late' => 'text-warning',
                                    'absent' => 'text-danger'
                                ][$attendance['status']] ?? 'text-secondary';
                                $status_text = ucfirst($attendance['status']);
                            } else {
                                $status_class = 'text-secondary';
                                $status_text = 'Not Marked';
                            }
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $employee['role'])); ?></td>
                            <td><?php echo $attendance && $attendance['check_in'] ? date('h:i A', strtotime($attendance['check_in'])) : '-'; ?></td>
                            <td><?php echo $attendance && $attendance['check_out'] ? date('h:i A', strtotime($attendance['check_out'])) : '-'; ?></td>
                            <td><?php echo $working_hours; ?></td>
                            <td class="<?php echo $status_class; ?>"><?php echo $status_text; ?></td>
                            <td><?php echo $attendance && !empty($attendance['notes']) ? substr(htmlspecialchars($attendance['notes']), 0, 50) . (strlen($attendance['notes']) > 50 ? '...' : '') : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <p>Generated on: <?php echo date('d/m/Y h:i A'); ?></p>
                    <p>Total Employees: <?php echo $daily_summary['total_employees']; ?> | 
                       Present: <?php echo $daily_summary['present']; ?> | 
                       Late: <?php echo $daily_summary['late']; ?> | 
                       Absent: <?php echo $daily_summary['absent']; ?></p>
                    <?php 
                    $marked = $daily_summary['present'] + $daily_summary['late'] + $daily_summary['absent'];
                    $rate = $daily_summary['total_employees'] > 0 ? ($marked / $daily_summary['total_employees']) * 100 : 0;
                    ?>
                    <p>Attendance Rate: <?php echo number_format($rate, 1); ?>%</p>
                </div>
            </body>
            </html>
        `;
        
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.print();
    }
    </script>

</body>
</html>