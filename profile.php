<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'] ?? 0;

// Fetch user details
$stmt = $pdo->prepare("
    SELECT u.*, s.shop_name, s.shop_code, b.business_name, b.business_code
    FROM users u
    LEFT JOIN shops s ON u.shop_id = s.id
    LEFT JOIN businesses b ON u.business_id = b.id
    WHERE u.id = ? AND u.business_id = ?
");
$stmt->execute([$user_id, $business_id]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header('Location: dashboard.php');
    exit();
}

// Get user statistics
$stats = [];

// Sales statistics
$sales_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_sales,
        SUM(total) as total_amount,
        SUM(pending_amount) as total_pending,
        AVG(total) as avg_sale
    FROM invoices 
    WHERE seller_id = ? 
      AND business_id = ?
      AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$sales_stmt->execute([$user_id, $business_id]);
$sales_stats = $sales_stmt->fetch();

// Recent activity (last 10 invoices)
$activity_stmt = $pdo->prepare("
    SELECT i.*, c.name as customer_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.seller_id = ? 
      AND i.business_id = ?
    ORDER BY i.created_at DESC 
    LIMIT 10
");
$activity_stmt->execute([$user_id, $business_id]);
$recent_activity = $activity_stmt->fetchAll();

// Attendance stats
$attendance_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_days,
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days
    FROM attendance 
    WHERE employee_id = ? 
      AND business_id = ?
      AND YEAR(attendance_date) = YEAR(CURDATE())
      AND MONTH(attendance_date) = MONTH(CURDATE())
");
$attendance_stmt->execute([$user_id, $business_id]);
$attendance_stats = $attendance_stmt->fetch();

// Check if user has recent check-in
$today_checkin_stmt = $pdo->prepare("
    SELECT check_in, check_out, status
    FROM attendance 
    WHERE employee_id = ? 
      AND business_id = ?
      AND attendance_date = CURDATE()
    LIMIT 1
");
$today_checkin_stmt->execute([$user_id, $business_id]);
$today_checkin = $today_checkin_stmt->fetch();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile - <?php echo htmlspecialchars($user['full_name']); ?></title>
    <?php include 'includes/head.php'; ?>
    <style>
        .profile-header {
            background: #ffffff;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #eaeaea;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: bold;
            color: #5b73e8;
            margin: 0 auto 15px;
            border: 3px solid #eaeaea;
        }
        .stat-card {
            border: 1px solid #eaeaea;
            border-radius: 8px;
            transition: all 0.3s;
            height: 100%;
            background: #fff;
        }
        .stat-card:hover {
            border-color: #5b73e8;
            box-shadow: 0 3px 10px rgba(91, 115, 232, 0.1);
        }
        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: #f8f9fa;
        }
        .badge-role {
            font-size: 13px;
            padding: 5px 10px;
            border-radius: 15px;
            font-weight: 500;
        }
        .activity-item {
            border-left: 3px solid #5b73e8;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        .attendance-card {
            border-radius: 10px;
            border: 1px solid #eaeaea;
            background: #fff;
        }
        .attendance-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #eaeaea;
        }
        .check-in-btn, .check-out-btn {
            font-size: 16px;
            font-weight: 500;
            padding: 10px 25px;
            border-radius: 6px;
            border: none;
        }
        .check-in-btn {
            background: #34c38f;
            color: white;
        }
        .check-in-btn:hover {
            background: #2ba87c;
        }
        .check-out-btn {
            background: #f46a6a;
            color: white;
        }
        .check-out-btn:hover {
            background: #e95959;
        }
        .status-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 8px;
        }
        .info-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #5b73e8;
        }
        .info-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 15px;
            font-weight: 500;
            color: #495057;
        }
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
            border-bottom: 1px solid #eaeaea;
        }
        .table td {
            border-bottom: 1px solid #eaeaea;
        }
        .btn-outline-primary {
            border-color: #5b73e8;
            color: #5b73e8;
        }
        .btn-outline-primary:hover {
            background-color: #5b73e8;
            color: white;
        }
        .text-primary {
            color: #5b73e8 !important;
        }
        .bg-primary {
            background-color: #5b73e8 !important;
        }
        .bg-success {
            background-color: #34c38f !important;
        }
        .bg-warning {
            background-color: #f1b44c !important;
        }
        .bg-danger {
            background-color: #f46a6a !important;
        }
        .bg-info {
            background-color: #50a5f1 !important;
        }
    </style>
</head>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include 'includes/sidebar.php'; ?>
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
                                <i class="bx bx-user-circle me-2"></i> My Profile
                            </h4>
                            <div class="d-flex gap-2">
                                <a href="profile_edit.php" class="btn btn-outline-primary">
                                    <i class="bx bx-edit me-1"></i> Edit Profile
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="row align-items-center">
                        <div class="col-md-3 text-center">
                            <div class="profile-avatar">
                                <?php echo strtoupper(substr($user['full_name'], 0, 2)); ?>
                            </div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                            <span class="badge badge-role bg-<?php 
                                echo match($user['role']) {
                                    'admin' => 'primary',
                                    'shop_manager' => 'info',
                                    'seller' => 'success',
                                    'cashier' => 'warning',
                                    'stock_manager' => 'secondary',
                                    'warehouse_manager' => 'purple',
                                    'field_executive' => 'dark',
                                    default => 'secondary'
                                };
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                            </span>
                        </div>
                        <div class="col-md-9">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="bx bx-building me-2"></i> Business Info</h6>
                                    <p class="mb-2"><strong><?php echo htmlspecialchars($user['business_name']); ?></strong></p>
                                    <p class="mb-2 text-muted">Code: <?php echo htmlspecialchars($user['business_code']); ?></p>
                                    <?php if($user['shop_name']): ?>
                                    <p class="mb-2 text-muted">
                                        <i class="bx bx-store-alt me-1"></i> 
                                        <?php echo htmlspecialchars($user['shop_name']); ?> (<?php echo $user['shop_code']; ?>)
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="bx bx-user-detail me-2"></i> Contact Info</h6>
                                    <p class="mb-2">
                                        <i class="bx bx-envelope me-1"></i>
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </p>
                                    <?php if($user['phone']): ?>
                                    <p class="mb-2">
                                        <i class="bx bx-phone me-1"></i>
                                        <?php echo htmlspecialchars($user['phone']); ?>
                                    </p>
                                    <?php endif; ?>
                                    <p class="mb-0 text-muted">
                                        <i class="bx bx-calendar me-1"></i>
                                        Member since: <?php echo date('F d, Y', strtotime($user['created_at'])); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attendance Section -->
                <div class="row mb-4">
                    <div class="col-lg-12">
                        <div class="card attendance-card">
                            <div class="attendance-header">
                                <h5 class="mb-0"><i class="bx bx-time me-2"></i> Today's Attendance</h5>
                            </div>
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <?php if($today_checkin): ?>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <h6>Status: <span class="badge bg-<?php 
                                                    echo $today_checkin['status'] == 'present' ? 'success' : 
                                                           ($today_checkin['status'] == 'late' ? 'warning' : 'danger'); 
                                                ?>"><?php echo ucfirst($today_checkin['status']); ?></span></h6>
                                            </div>
                                            <div class="col-md-4">
                                                <p class="mb-0"><strong>Check-in:</strong><br>
                                                <?php echo $today_checkin['check_in'] ? date('h:i A', strtotime($today_checkin['check_in'])) : '--:--'; ?></p>
                                            </div>
                                            <div class="col-md-4">
                                                <p class="mb-0"><strong>Check-out:</strong><br>
                                                <?php echo $today_checkin['check_out'] ? date('h:i A', strtotime($today_checkin['check_out'])) : 'Not checked out'; ?></p>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <p class="mb-0 text-muted">You haven't checked in today.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4 text-center">
                                        <?php if($today_checkin && !$today_checkin['check_out']): ?>
                                        <button class="btn check-out-btn" onclick="checkOut()">
                                            <i class="bx bx-log-out me-1"></i> Check Out
                                        </button>
                                        <?php elseif(!$today_checkin): ?>
                                        <button class="btn check-in-btn" onclick="checkIn()">
                                            <i class="bx bx-log-in me-1"></i> Check In
                                        </button>
                                        <?php else: ?>
                                        <span class="badge bg-success">Attendance completed for today</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                

                <!-- Recent Activity & Account Details -->
               
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/rightbar.php'; ?>
<?php include 'includes/scripts.php'; ?>

<script>
// Check-in function
function checkIn() {
    if(confirm('Check in for today?')) {
        $.ajax({
            url: 'ajax/attendance_checkin.php',
            type: 'POST',
            data: { action: 'checkin' },
            success: function(response) {
                const data = JSON.parse(response);
                if(data.success) {
                    alert('Checked in successfully! Status: ' + data.status);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            },
            error: function() {
                alert('Network error. Please try again.');
            }
        });
    }
}

// Check-out function
function checkOut() {
    if(confirm('Check out for today?')) {
        $.ajax({
            url: 'ajax/attendance_checkin.php',
            type: 'POST',
            data: { action: 'checkout' },
            success: function(response) {
                const data = JSON.parse(response);
                if(data.success) {
                    alert('Checked out successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            },
            error: function() {
                alert('Network error. Please try again.');
            }
        });
    }
}

// Update topbar profile dropdown to link to this page
$(document).ready(function() {
    // Find the profile link in dropdown and update it
    $('.dropdown-menu').find('a[href="#"]').each(function() {
        if($(this).text().trim() === 'Profile' || $(this).find('.dripicons-user').length) {
            $(this).attr('href', 'profile.php');
        }
    });
});
</script>
</body>
</html>