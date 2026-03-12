<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id   = $_SESSION['user_id'];
$shop_name = $_SESSION['current_shop_name'] ?? 'All Shops';

// Get user info
$user_stmt = $pdo->prepare("SELECT full_name, role FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user_info = $user_stmt->fetch();

// Get selected month
$selected_month = $_GET['month'] ?? date('Y-m');

// Get attendance records (FIXED QUERY - no collation issues)
$sql = "SELECT 
        DATE(attendance_date) as date,
        DATE_FORMAT(attendance_date, '%d %b %Y') as date_formatted,
        TIME(check_in) as check_in_raw,
        TIME(check_out) as check_out_raw,
        DATE_FORMAT(check_in, '%h:%i %p') as check_in_formatted,
        DATE_FORMAT(check_out, '%h:%i %p') as check_out_formatted,
        TIMEDIFF(check_out, check_in) as work_duration,
        status
        FROM attendance 
        WHERE employee_id = ? 
        AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
        ORDER BY attendance_date DESC";

$stmt = $pdo->prepare($sql);

$records = $stmt->fetchAll();

// Get statistics (FIXED QUERY)
$stats_sql = "SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days,
        SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days
        FROM attendance 
        WHERE employee_id = ? 
        AND DATE_FORMAT(attendance_date, '%Y-%m') = ?";

$stats_stmt = $pdo->prepare($stats_sql);

$stats = $stats_stmt->fetch();

// Calculate attendance percentage
$attendance_percentage = 0;


// Get working days in month
$month_start = date('Y-m-01', strtotime($selected_month));
$month_end = date('Y-m-t', strtotime($selected_month));
$working_days = 0;
$current = strtotime($month_start);
$last = strtotime($month_end);
while ($current <= $last) {
    $day = date('N', $current);
    if ($day < 6) $working_days++;
    $current = strtotime('+1 day', $current);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance • <?= htmlspecialchars($user_info['full_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #4361ee;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #ff9e00;
            --info: #7209b7;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 100vh;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 10px 35px rgba(31, 38, 135, 0.15);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
        }
        
        .header-section {
            background: linear-gradient(135deg, #4361ee, #3a56d4);
            border-radius: 20px;
            padding: 30px;
            color: white;
            margin-bottom: 30px;
        }
        
        .stat-card {
            padding: 25px;
            border-radius: 16px;
            color: white;
            text-align: center;
            transition: transform 0.3s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-total { background: linear-gradient(135deg, #4776E6, #8E54E9); }
        .stat-present { background: linear-gradient(135deg, #00b09b, #96c93d); }
        .stat-late { background: linear-gradient(135deg, #ff9a00, #ff5e00); }
        .stat-absent { background: linear-gradient(135deg, #ff416c, #ff4b2b); }
        .stat-leave { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-half { background: linear-gradient(135deg, #6a11cb, #2575fc); }
        
        .progress-container {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
        }
        
        .progress-custom {
            height: 12px;
            border-radius: 6px;
            background: rgba(255,255,255,0.2);
            overflow: hidden;
        }
        
        .progress-custom .progress-bar {
            background: linear-gradient(90deg, #4cc9f0, #4361ee);
            border-radius: 6px;
        }
        
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 25px rgba(0,0,0,0.08);
        }
        
        .table-custom {
            margin: 0;
        }
        
        .table-custom thead {
            background: linear-gradient(90deg, #4361ee, #3a56d4);
            color: white;
        }
        
        .table-custom th {
            border: none;
            padding: 15px;
            font-weight: 600;
        }
        
        .table-custom td {
            padding: 15px;
            vertical-align: middle;
            border-color: #eee;
        }
        
        .table-custom tbody tr {
            transition: all 0.2s;
        }
        
        .table-custom tbody tr:hover {
            background: rgba(67, 97, 238, 0.05);
            transform: translateX(5px);
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
            min-width: 80px;
            text-align: center;
        }
        
        .badge-present { background: #d4edda; color: #155724; }
        .badge-late { background: #fff3cd; color: #856404; }
        .badge-absent { background: #f8d7da; color: #721c24; }
        .badge-leave { background: #e2e3e5; color: #383d41; }
        .badge-half { background: #d1ecf1; color: #0c5460; }
        
        .time-badge {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        .month-selector {
            background: rgba(255,255,255,0.1);
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 12px;
            padding: 10px 20px;
            color: white;
            font-weight: 600;
            backdrop-filter: blur(5px);
        }
        
        .month-selector:focus {
            background: rgba(255,255,255,0.2);
            border-color: white;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.3);
        }
        
        .action-btn {
            padding: 10px 25px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #00b09b, #96c93d);
            color: white;
        }
        
        .btn-export {
            background: linear-gradient(135deg, #4776E6, #8E54E9);
            color: white;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .glass-card { padding: 20px; }
            .header-section { padding: 20px; }
            .stat-card { padding: 15px; }
            .table-custom td, .table-custom th { padding: 10px; }
        }
    </style>
</head>
<body>
<div class="container py-4">
    <!-- Header -->
    <div class="header-section">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h3 class="mb-2"><i class="bi bi-calendar2-check"></i> My Attendance Records</h3>
                <p class="mb-0 opacity-75">
                    <i class="bi bi-person"></i> <?= htmlspecialchars($user_info['full_name']) ?> • 
                    <i class="bi bi-shop"></i> <?= htmlspecialchars($shop_name) ?>
                </p>
            </div>
            <div class="col-md-4 text-md-end">
                <a href="attendance.php" class="action-btn btn-back">
                    <i class="bi bi-arrow-left"></i> Back to Punch
                </a>
            </div>
        </div>
    </div>

    <!-- Month Selector -->
    <div class="glass-card">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5 class="mb-0"><i class="bi bi-calendar-month"></i> 
                    <?= date('F Y', strtotime($selected_month . '-01')) ?>
                </h5>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-3 justify-content-md-end">
                    <select class="month-selector" onchange="window.location.href='emp_attendance.php?month=' + this.value">
                        <option value="<?= date('Y-m') ?>" <?= $selected_month === date('Y-m') ? 'selected' : '' ?>>This Month</option>
                        <option value="<?= date('Y-m', strtotime('-1 month')) ?>" <?= $selected_month === date('Y-m', strtotime('-1 month')) ? 'selected' : '' ?>>Last Month</option>
                        <?php for($i = 2; $i <= 6; $i++): ?>
                        <option value="<?= date('Y-m', strtotime("-$i month")) ?>">
                            <?= date('F Y', strtotime("-$i month")) ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-6">
            <div class="stat-card stat-total">
                <h2 class="mb-2"><?= $stats['total_days'] ?? 0 ?></h2>
                <p class="mb-0">Total Days</p>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="stat-card stat-present">
                <h2 class="mb-2"><?= $stats['present_days'] ?? 0 ?></h2>
                <p class="mb-0">Present</p>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="stat-card stat-late">
                <h2 class="mb-2"><?= $stats['late_days'] ?? 0 ?></h2>
                <p class="mb-0">Late</p>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="stat-card stat-absent">
                <h2 class="mb-2"><?= $stats['absent_days'] ?? 0 ?></h2>
                <p class="mb-0">Absent</p>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="stat-card stat-leave">
                <h2 class="mb-2"><?= $stats['leave_days'] ?? 0 ?></h2>
                <p class="mb-0">Leave</p>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="stat-card stat-half">
                <h2 class="mb-2"><?= $stats['half_days'] ?? 0 ?></h2>
                <p class="mb-0">Half Day</p>
            </div>
        </div>
    </div>

    <!-- Attendance Progress -->
    <div class="glass-card">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h6 class="mb-3">Attendance Progress</h6>
                <div class="progress-container">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Attendance Rate</span>
                        <span class="fw-bold"><?= number_format($attendance_percentage, 1) ?>%</span>
                    </div>
                    <div class="progress-custom">
                        <div class="progress-bar" style="width: <?= $attendance_percentage ?>%"></div>
                    </div>
                    <div class="row mt-3 text-center">
                        <div class="col-4">
                            <small class="text-muted">Working Days</small>
                            <h5 class="mt-1"><?= $working_days ?></h5>
                        </div>
                        <div class="col-4">
                            <small class="text-muted">Present</small>
                            <h5 class="mt-1 text-success"><?= $stats['present_days'] ?? 0 ?></h5>
                        </div>
                        <div class="col-4">
                            <small class="text-muted">Attendance %</small>
                            <h5 class="mt-1 text-primary"><?= number_format($attendance_percentage, 1) ?>%</h5>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center">
                    <div class="position-relative d-inline-block">
                        <svg width="120" height="120" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="54" fill="none" stroke="#e9ecef" stroke-width="12"/>
                            <circle cx="60" cy="60" r="54" fill="none" stroke="#4361ee" stroke-width="12" 
                                    stroke-dasharray="<?= 339.292 * $attendance_percentage / 100 ?> 339.292"
                                    stroke-linecap="round" transform="rotate(-90 60 60)"/>
                        </svg>
                        <div class="position-absolute top-50 start-50 translate-middle">
                            <h3 class="mb-0"><?= number_format($attendance_percentage, 0) ?>%</h3>
                            <small class="text-muted">Rate</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Records -->
    <div class="glass-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="mb-0"><i class="bi bi-list-check"></i> Attendance Details</h5>
            <div class="d-flex gap-2">
                <button onclick="printReport()" class="action-btn btn-print">
                    <i class="bi bi-printer"></i> Print
                </button>
                <button onclick="exportReport()" class="action-btn btn-export">
                    <i class="bi bi-download"></i> Export
                </button>
            </div>
        </div>
        
        <?php if (empty($records)): ?>
        <div class="empty-state">
            <i class="bi bi-calendar-x empty-icon"></i>
            <h5 class="text-muted mb-3">No attendance records found</h5>
            <p class="text-muted mb-4">You have no attendance records for <?= date('F Y', strtotime($selected_month . '-01')) ?></p>
            <a href="attendance.php" class="btn btn-primary">
                <i class="bi bi-clock"></i> Go to Attendance
            </a>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="table table-custom">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Duration</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): 
                        $day = date('D', strtotime($record['date']));
                        $is_weekend = in_array($day, ['Sat', 'Sun']);
                        $duration = $record['work_duration'] ?: null;
                        $duration_display = '--:--';
                        
                        if ($duration) {
                            list($h, $m, $s) = explode(':', $duration);
                            $duration_display = $h . 'h ' . $m . 'm';
                        }
                    ?>
                    <tr class="<?= $is_weekend ? 'table-info' : '' ?>">
                        <td>
                            <strong><?= $record['date_formatted'] ?></strong>
                            <?php if ($is_weekend): ?>
                                <br><small class="text-info"><i class="bi bi-star"></i> Weekend</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark p-2">
                                <?= $day ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($record['check_in_formatted']): ?>
                                <span class="time-badge">
                                    <i class="bi bi-box-arrow-in-right"></i> <?= $record['check_in_formatted'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">--:--</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($record['check_out_formatted']): ?>
                                <span class="time-badge">
                                    <i class="bi bi-box-arrow-right"></i> <?= $record['check_out_formatted'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">--:--</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="time-badge">
                                <i class="bi bi-clock"></i> <?= $duration_display ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $status_class = '';
                            switch($record['status']) {
                                case 'present': $status_class = 'badge-present'; break;
                                case 'late': $status_class = 'badge-late'; break;
                                case 'absent': $status_class = 'badge-absent'; break;
                                case 'leave': $status_class = 'badge-leave'; break;
                                case 'half_day': $status_class = 'badge-half'; break;
                                default: $status_class = 'badge-present';
                            }
                            ?>
                            <span class="status-badge <?= $status_class ?>">
                                <?= ucfirst($record['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary Footer -->
        <div class="mt-4 pt-4 border-top">
            <div class="row">
                <div class="col-md-6">
                    <h6>Summary for <?= date('F Y', strtotime($selected_month . '-01')) ?></h6>
                    <div class="d-flex gap-4">
                        <div>
                            <small class="text-muted">Total Records</small>
                            <h5 class="mt-1"><?= count($records) ?></h5>
                        </div>
                        <div>
                            <small class="text-muted">Present Days</small>
                            <h5 class="mt-1 text-success"><?= $stats['present_days'] ?? 0 ?></h5>
                        </div>
                        <div>
                            <small class="text-muted">Attendance Rate</small>
                            <h5 class="mt-1 text-primary"><?= number_format($attendance_percentage, 1) ?>%</h5>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted">Report generated on</small>
                    <h6 class="mt-1"><?= date('d M Y h:i A') ?></h6>
                    <small class="text-muted">Employee ID: <?= $user_id ?></small>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Print report
function printReport() {
    const originalContent = document.body.innerHTML;
    const reportContent = document.querySelector('.glass-card').innerHTML;
    
    document.body.innerHTML = `
        <div class="container mt-4">
            <div class="text-center mb-4">
                <h3>Attendance Report</h3>
                <p>Employee: <?= htmlspecialchars($user_info['full_name']) ?> | Period: <?= date('F Y', strtotime($selected_month . '-01')) ?></p>
            </div>
            ${reportContent}
        </div>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

// Export to CSV
function exportReport() {
    let csv = 'Date,Day,Check In,Check Out,Duration,Status\n';
    
    <?php foreach ($records as $record): 
        $day = date('D', strtotime($record['date']));
        $duration = $record['work_duration'] ?: '--:--';
        if ($duration !== '--:--') {
            list($h, $m, $s) = explode(':', $duration);
            $duration = $h . 'h ' . $m . 'm';
        }
    ?>
    csv += '<?= $record['date_formatted'] ?>,<?= $day ?>,<?= $record['check_in_formatted'] ?: "--:--" ?>,<?= $record['check_out_formatted'] ?: "--:--" ?>,<?= $duration ?>,<?= ucfirst($record['status']) ?>\n';
    <?php endforeach; ?>
    
    // Add summary
    csv += '\nSummary\n';
    csv += 'Total Records,<?= count($records) ?>\n';
    csv += 'Present Days,<?= $stats['present_days'] ?? 0 ?>\n';
    csv += 'Attendance Rate,<?= number_format($attendance_percentage, 1) ?>%\n';
    csv += 'Generated On,<?= date('d/m/Y H:i') ?>\n';
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'attendance_<?= $selected_month ?>_<?= str_replace(' ', '_', $user_info['full_name']) ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    if (e.altKey) {
        if (e.key === 'p') printReport();
        if (e.key === 'e') exportReport();
        if (e.key === 'b') window.location.href = 'attendance.php';
    }
});
</script>
</body>
</html>