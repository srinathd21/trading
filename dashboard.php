<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

// ==================== LOGIN CHECK ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// ==================== ROLE & USER INFO ====================
$user_role = $_SESSION['role'] ?? '';
$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'User';

$is_admin        = ($user_role === 'admin');
$is_shop_manager = in_array($user_role, ['admin', 'shop_manager']);
$is_seller       = in_array($user_role, ['admin', 'shop_manager', 'seller', 'cashier']);
$is_stock_manager= in_array($user_role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager']);
$is_field_executive = ($user_role === 'field_executive');
$is_staff = in_array($user_role, ['staff', 'warehouse_manager']);

// ==================== BUSINESS & SHOP SELECTION ====================
$current_business_id = $_SESSION['current_business_id'] ?? null;
$current_shop_id     = $_SESSION['current_shop_id'] ?? null;
$current_shop_name   = $_SESSION['current_shop_name'] ?? 'All Shops';
$current_business_name = $_SESSION['current_business_name'] ?? 'Business';

// Non-admin must have a business and shop selected
if (!$is_admin && (!$current_business_id || !$current_shop_id)) {
    header('Location: select_shop.php');
    exit();
}

// ==================== CLOUD RENEWAL NOTIFICATION ====================
$cloud_renewal_notification = false;
$cloud_expiry_date = null;
$cloud_days_left = 0;
$cloud_status = '';
$cloud_plan = '';
$show_timer_in_header = false;
$timer_seconds_left = 0;
$one_month_before = false;
$expiry_timestamp_end_of_day = 0;

if ($current_business_id) {
    // Get business cloud subscription details
    $stmt = $pdo->prepare("
        SELECT cloud_expiry_date, cloud_subscription_status, cloud_plan 
        FROM businesses 
        WHERE id = ?
    ");
    $stmt->execute([$current_business_id]);
    $business_cloud = $stmt->fetch();
    
    if ($business_cloud) {
        $cloud_expiry_date = $business_cloud['cloud_expiry_date'];
        $cloud_status = $business_cloud['cloud_subscription_status'];
        $cloud_plan = $business_cloud['cloud_plan'];
        
        // Check if subscription is expired or about to expire
        if ($cloud_expiry_date) {
            // Calculate expiry at END OF DAY (23:59:59)
            $expiry_date_obj = new DateTime($cloud_expiry_date);
            $expiry_date_obj->setTime(23, 59, 59); // End of day
            $expiry_timestamp_end_of_day = $expiry_date_obj->getTimestamp();
            
            $current_timestamp = time();
            $seconds_left = $expiry_timestamp_end_of_day - $current_timestamp;
            $days_left = ceil($seconds_left / (24 * 60 * 60)); // Ceiling for partial days
            
            // Check if subscription is already expired (after end of day)
            if ($seconds_left <= 0 || $cloud_status === 'expired' || $cloud_status === 'cancelled') {
                // Subscription expired - redirect to renewal page
                header("Location: cloud_renewal.php");
                exit();
            }
            
            // Show timer in header if less than 30 days left
            if ($days_left <= 30) {
                $show_timer_in_header = true;
                $timer_seconds_left = $seconds_left;
                $cloud_renewal_notification = true;
                $cloud_days_left = $days_left;
                
                // Check if it's 1 month before (exactly 30 days)
                if ($days_left == 30) {
                    $one_month_before = true;
                }
            }
        }
    }
}

// ==================== CHECK FOR 1-MONTH MODAL ====================
// This will show a modal exactly 30 days before expiry
$show_one_month_modal = false;
if ($one_month_before && !isset($_COOKIE['cloud_one_month_shown'])) {
    $show_one_month_modal = true;
    // Set cookie to show modal only once
    setcookie('cloud_one_month_shown', '1', time() + (86400 * 30), '/'); // 30 days
}

$today      = date('Y-m-d');
$this_month = date('Y-m-01');
$yesterday  = date('Y-m-d', strtotime('-1 day'));

// Default KPI values
$today_gross_revenue = $today_gross_sales = $month_gross_revenue = $month_gross_sales = 0;
$today_returns = $month_returns = 0;
$today_net_revenue = $month_net_revenue = 0;
$yesterday_gross_revenue = $yesterday_gross_sales = $yesterday_returns = $yesterday_net_revenue = 0;
$shop_stock_value = $low_stock_items = $today_expenses = $pending_transfers = 0;
$pending_invoices = $pending_payments = $pending_requirements = $active_customers = 0;
$total_products = $out_of_stock = 0;
$recent_sales = [];
$trend = [];

// ==================== KPIs (Only if a business is selected) ====================
if ($current_business_id) {

    // Helper function to execute revenue query with proper shop filter
    function getRevenueData($pdo, $dateCondition, $dateParams, $current_business_id, $current_shop_id, $is_admin) {

    $shopSql = "";
    $params = array_merge($dateParams, [$current_business_id]);

    if (!$is_admin) {
        $shopSql = " AND i.shop_id = ?";
        $params[] = $current_shop_id;
    }

    $sql = "
        SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(i.total), 0) AS gross_rev,
            COALESCE(SUM(r.returns), 0) AS returns
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(return_qty * unit_price) AS returns
            FROM invoice_items
            GROUP BY invoice_id
        ) r ON r.invoice_id = i.id
        WHERE $dateCondition
          AND i.business_id = ?
          $shopSql
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

    // 1. Today's Gross & Net Revenue
    $todayData = getRevenueData(
        $pdo,
        "DATE(i.created_at) = ?",
        [$today],
        $current_business_id,
        $current_shop_id,
        $is_admin
    );
    $today_gross_sales = $todayData['cnt'] ?? 0;
    $today_gross_revenue = $todayData['gross_rev'] ?? 0;
    $today_returns = $todayData['returns'] ?? 0;
    $today_net_revenue = $today_gross_revenue - $today_returns;

    // 2. Yesterday's
    $yesterdayData = getRevenueData(
        $pdo,
        "DATE(i.created_at) = ?",
        [$yesterday],
        $current_business_id,
        $current_shop_id,
        $is_admin
    );
    $yesterday_gross_sales = $yesterdayData['cnt'] ?? 0;
    $yesterday_gross_revenue = $yesterdayData['gross_rev'] ?? 0;
    $yesterday_returns = $yesterdayData['returns'] ?? 0;
    $yesterday_net_revenue = $yesterday_gross_revenue - $yesterday_returns;

    // 3. This Month
    $monthData = getRevenueData(
        $pdo,
        "i.created_at >= ?",
        [$this_month],
        $current_business_id,
        $current_shop_id,
        $is_admin
    );
    $month_gross_sales = $monthData['cnt'] ?? 0;
    $month_gross_revenue = $monthData['gross_rev'] ?? 0;
    $month_returns = $monthData['returns'] ?? 0;
    $month_net_revenue = $month_gross_revenue - $month_returns;

    // 4. Pending Invoices
    $sql = "SELECT
                COUNT(DISTINCT i.id) AS cnt,
                COALESCE(SUM(i.pending_amount), 0) AS amt
            FROM invoices i
            WHERE i.pending_amount > 0
              AND i.business_id = ?";
    $params = [$current_business_id];
    if (!$is_admin) {
        $sql .= " AND i.shop_id = ?";
        $params[] = $current_shop_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    $pending_invoices = $row['cnt'] ?? 0;
    $pending_invoices_amount = $row['amt'] ?? 0;
// 5. Current Stock Value (for current shop only)
$sql = "SELECT COALESCE(SUM(ps.quantity * p.stock_price), 0)
        FROM product_stocks ps
        JOIN products p ON ps.product_id = p.id
        WHERE ps.business_id = ?
          AND p.is_active = 1
          AND ps.shop_id = ?";  // Added shop filter

$params = [$current_business_id, $current_shop_id];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shop_stock_value = $stmt->fetchColumn();
// 6. Low Stock Items (for current shop only)
$sql = "SELECT COUNT(DISTINCT p.id)
        FROM products p
        LEFT JOIN product_stocks ps ON p.id = ps.product_id 
            AND ps.shop_id = ?  -- Only for current shop
        WHERE p.is_active = 1
          AND p.business_id = ?
          AND ps.quantity IS NOT NULL  -- Must have stock record
          AND ps.quantity < p.min_stock_level
          AND ps.quantity > 0";  // Greater than zero but below min

$params = [$current_shop_id, $current_business_id];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$low_stock_items = $stmt->fetchColumn();
   // In the dashboard.php file, replace the out_of_stock query with:

// 7. Out of Stock (for current shop only - matching products page logic)
$sql = "SELECT COUNT(DISTINCT p.id)
        FROM products p
        LEFT JOIN product_stocks ps ON p.id = ps.product_id 
            AND ps.shop_id = ?  -- Only join for the current shop
        WHERE p.is_active = 1
          AND p.business_id = ?
          AND (ps.quantity IS NULL OR ps.quantity = 0)";  // Products with no stock record OR zero quantity

$params = [$current_shop_id, $current_business_id];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$out_of_stock = $stmt->fetchColumn();

    // 8. Total Active Products
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE is_active = 1 AND business_id = ?");
    $stmt->execute([$current_business_id]);
    $total_products = $stmt->fetchColumn();

    // 9. Today's Expenses
    $sql = "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date = ? AND business_id = ?";
    $params = [$today, $current_business_id];
    if (!$is_admin) {
        $sql .= " AND shop_id = ?";
        $params[] = $current_shop_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $today_expenses = $stmt->fetchColumn();

    // 10. Pending Stock Transfers
    $sql = "SELECT COUNT(*) FROM stock_transfers WHERE status IN ('pending', 'approved', 'in_transit') AND business_id = ?";
    $params = [$current_business_id];
    if (!$is_admin) {
        $sql .= " AND (from_shop_id = ? OR to_shop_id = ?)";
        $params = array_merge($params, [$current_shop_id, $current_shop_id]);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pending_transfers = $stmt->fetchColumn();

    // 11. Active Customers (last 30 days)
    $sql = "SELECT COUNT(DISTINCT customer_id)
            FROM invoices
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND business_id = ?";
    $params = [$current_business_id];
    if (!$is_admin) {
        $sql .= " AND shop_id = ?";
        $params[] = $current_shop_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $active_customers = $stmt->fetchColumn();

    // 12. Pending Requirements
    if ($is_field_executive || $is_admin) {
        if ($is_field_executive) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM store_requirements WHERE requirement_status = 'pending' AND business_id = ? AND field_executive_id = ?");
            $stmt->execute([$current_business_id, $user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM store_requirements WHERE requirement_status = 'pending' AND business_id = ?");
            $stmt->execute([$current_business_id]);
        }
        $pending_requirements = $stmt->fetchColumn();
    }

    // 13. Pending Supplier Payments
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE payment_status IN ('unpaid', 'partial') AND business_id = ?");
    $stmt->execute([$current_business_id]);
    $pending_payments = $stmt->fetchColumn();

    // 14. Recent Sales
    $sql = "SELECT
                i.invoice_number,
                i.total AS gross_total,
                COALESCE(SUM(ii.return_qty * ii.unit_price), 0) AS returns,
                (i.total - COALESCE(SUM(ii.return_qty * ii.unit_price), 0)) AS net_total,
                i.created_at,
                COALESCE(c.name, 'Walk-in Customer') AS customer
            FROM invoices i
            LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.business_id = ?";
    $params = [$current_business_id];
    if (!$is_admin) {
        $sql .= " AND i.shop_id = ?";
        $params[] = $current_shop_id;
    }
    $sql .= " GROUP BY i.id ORDER BY i.created_at DESC LIMIT 5";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recent_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 15. Monthly Trend
    $trend = [];
    for ($i = 5; $i >= 0; $i--) {
        $start = date('Y-m-01', strtotime("-$i month"));
        $end = date('Y-m-t', strtotime("-$i month"));

        $sql = "SELECT
                    COALESCE(SUM(i.total), 0) AS gross_revenue,
                    COALESCE(SUM(ii.return_qty * ii.unit_price), 0) AS returns
                FROM invoices i
                LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
                WHERE i.created_at BETWEEN ? AND ?
                  AND i.business_id = ?";
        $params = [$start, $end, $current_business_id];
        if (!$is_admin) {
            $sql .= " AND i.shop_id = ?";
            $params[] = $current_shop_id;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        $gross = (float)($row['gross_revenue'] ?? 0);
        $returns = (float)($row['returns'] ?? 0);
        $net = $gross - $returns;

        $trend[] = [
            'month' => date('M Y', strtotime($start)),
            'gross' => $gross,
            'returns' => $returns,
            'net' => $net
        ];
    }

    // Return percentages
    $today_return_percentage = $today_gross_revenue > 0 ? ($today_returns / $today_gross_revenue) * 100 : 0;
    $month_return_percentage = $month_gross_revenue > 0 ? ($month_returns / $month_gross_revenue) * 100 : 0;
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = "Dashboard - " . htmlspecialchars($current_shop_name); include 'includes/head.php'; ?>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include 'includes/topbar.php'; ?>
    <div class="vertical-menu"><div data-simplebar class="h-100">
        <?php include 'includes/sidebar.php'; ?>
    </div></div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Cloud Renewal Timer Modal (1 Month Before) -->
                <?php if ($show_one_month_modal): ?>
                <div class="modal fade" id="oneMonthModal" tabindex="-1" aria-labelledby="oneMonthModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-gradient-warning text-white border-0">
                                <h5 class="modal-title" id="oneMonthModalLabel">
                                    <i class="bx bx-cloud fs-4 me-2"></i> Cloud Subscription Renewal Notice
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="text-center mb-4">
                                    <div class="avatar-lg rounded-circle bg-warning bg-opacity-10 p-4 d-inline-block mb-3">
                                        <i class="bx bx-alarm fs-1 text-warning"></i>
                                    </div>
                                    <h4 class="mb-2">Your Cloud Subscription Expires in 30 Days!</h4>
                                    <p class="text-muted">Plan: <strong><?= htmlspecialchars($cloud_plan) ?></strong> | Expires on: <strong><?= date('d M Y', strtotime($cloud_expiry_date)) ?></strong></p>
                                    <p class="text-muted"><small>Service will expire at 11:59 PM on <?= date('d M Y', strtotime($cloud_expiry_date)) ?></small></p>
                                </div>
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <div class="card border-warning border-2 text-center h-100">
                                            <div class="card-body py-4">
                                                <h1 class="text-warning mb-2" id="modalDaysLeft">30</h1>
                                                <p class="mb-0 text-muted">Full Days Left</p>
                                                <small class="text-muted">Until 11:59 PM</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body">
                                                <h6 class="mb-3"><i class="bx bx-info-circle me-2 text-warning"></i>Important Information</h6>
                                                <ul class="list-unstyled mb-0">
                                                    <li class="mb-2"><i class="bx bx-check-circle text-success me-2"></i> Renew early to avoid service interruption</li>
                                                    <li class="mb-2"><i class="bx bx-check-circle text-success me-2"></i> Current plan: <?= htmlspecialchars($cloud_plan) ?></li>
                                                    <li class="mb-2"><i class="bx bx-check-circle text-success me-2"></i> Yearly plan recommended for best value</li>
                                                    <li class="mb-2"><i class="bx bx-check-circle text-success me-2"></i> Service available until end of day on expiry date</li>
                                                    <li><i class="bx bx-check-circle text-success me-2"></i> Renew now to get uninterrupted service</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-center">
                                    <div class="timer-display mb-3">
                                        <h3 class="mb-2">Time Remaining Until End of Day:</h3>
                                        <div class="d-flex justify-content-center">
                                            <div class="timer-box me-2">
                                                <span class="days">30</span>
                                                <small>Days</small>
                                            </div>
                                            <div class="timer-box me-2">
                                                <span class="hours">23</span>
                                                <small>Hours</small>
                                            </div>
                                            <div class="timer-box me-2">
                                                <span class="minutes">59</span>
                                                <small>Minutes</small>
                                            </div>
                                            <div class="timer-box">
                                                <span class="seconds">59</span>
                                                <small>Seconds</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-center gap-3">
                                        <a href="cloud_renewal.php" class="btn btn-warning btn-lg px-4">
                                            <i class="bx bx-sync me-2"></i> Renew Now
                                        </a>
                                        <button type="button" class="btn btn-outline-warning btn-lg px-4" data-bs-dismiss="modal">
                                            <i class="bx bx-alarm me-2"></i> Remind Me Later
                                        </button>
                                    </div>
                                    <p class="text-muted mt-3 mb-0">
                                        <small><i class="bx bx-info-circle me-1"></i> Timer counts down to 11:59 PM on <?= date('d M Y', strtotime($cloud_expiry_date)) ?></small>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Modern Welcome Header with Timer -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card bg-gradient-primary border-0 shadow-sm overflow-hidden">
                            <div class="card-body p-3 p-md-4">
                                <div class="row align-items-center">
                                    <div class="col-md-<?= $show_timer_in_header ? '6' : '8' ?>">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <div class="avatar-lg rounded-circle bg-white bg-opacity-25 p-3">
                                                    <i class="bx bx-store-alt fs-1 text-white"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h3 class="text-white mb-1">Welcome back, <?= htmlspecialchars($user_name) ?>!</h3>
                                                <p class="text-white mb-0">
                                                    <i class="bx bx-building me-1"></i> <?= htmlspecialchars($current_business_name) ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="bx bx-store me-1"></i> <?= htmlspecialchars($current_shop_name) ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="bx bx-calendar me-1"></i> <?= date('l, F j, Y') ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="bx bx-time me-1"></i> <?= date('h:i A') ?>
                                                    <?php if ($today_returns > 0): ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="bx bx-undo me-1 text-warning"></i> Returns: ₹<?= number_format($today_returns, 0) ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($show_timer_in_header): ?>
                                    <div class="col-md-<?= $show_timer_in_header ? '6' : '4' ?> mt-3 mt-md-0">
                                        <div class="cloud-timer-container bg-white bg-opacity-10 rounded-3 p-3 border border-white border-opacity-25">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm rounded-circle bg-white bg-opacity-25 p-2 me-3">
                                                        <i class="bx bx-cloud fs-4 text-white"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="text-white mb-0">Cloud Expires in</h6>
                                                        <p class="text-white mb-0 small">
                                                            Plan: <?= htmlspecialchars($cloud_plan) ?> | 
                                                            Date: <?= date('d M Y', strtotime($cloud_expiry_date)) ?>
                                                        </p>
                                                        <p class="text-white mb-0 small">
                                                            <i class="bx bx-time me-1"></i> Until 11:59 PM
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <!-- Timer Display -->
                                                    <div class="timer-countdown" id="headerTimer">
                                                        <div class="d-flex justify-content-end">
                                                            <div class="timer-segment me-1">
                                                                <span class="timer-value days"><?= str_pad($cloud_days_left, 2, '0', STR_PAD_LEFT) ?></span>
                                                                <small class="timer-label text-white">D</small>
                                                            </div>
                                                            <div class="timer-segment me-1">
                                                                <span class="timer-value hours">00</span>
                                                                <small class="timer-label text-white">H</small>
                                                            </div>
                                                            <div class="timer-segment me-1">
                                                                <span class="timer-value minutes">00</span>
                                                                <small class="timer-label text-white">M</small>
                                                            </div>
                                                            <div class="timer-segment">
                                                                <span class="timer-value seconds">00</span>
                                                                <small class="timer-label text-white">S</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <a href="cloud_renewal.php" class="btn btn-sm btn-light mt-2 px-3">
                                                        <i class="bx bx-sync me-1"></i> Renew
                                                    </a>
                                                </div>
                                            </div>
                                            <!-- Progress Bar -->
                                            <?php if ($cloud_days_left <= 10): ?>
                                            <div class="mt-2">
                                                <div class="progress bg-white bg-opacity-25" style="height: 4px;">
                                                    <div class="progress-bar bg-<?= $cloud_days_left <= 3 ? 'danger' : ($cloud_days_left <= 7 ? 'warning' : 'info') ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= ((30 - $cloud_days_left) / 30) * 100 ?>%"></div>
                                                </div>
                                                <small class="text-white d-block mt-1 text-center">
                                                    <?= $cloud_days_left ?> full day<?= $cloud_days_left != 1 ? 's' : '' ?> remaining
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-12">
    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">Quick Actions</h5>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($is_seller || $is_admin): ?>
                <a href="pos.php" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1">
                    <i class="bx bx-plus fs-6"></i>
                    <span>New Sale</span>
                </a>
                <?php endif; ?>
                
                <?php if ($is_seller || $is_admin): ?>
                <a href="invoices.php" class="btn btn-sm btn-outline-success d-flex align-items-center gap-1">
                    <i class="bx bx-receipt fs-6"></i>
                    <span>Invoices</span>
                </a>
                <?php endif; ?>
                
                <?php if ($is_stock_manager || $is_admin): ?>
                <a href="stock_transfers.php" class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1">
                    <i class="bx bx-transfer fs-6"></i>
                    <span>Stock Transfer</span>
                </a>
                <?php endif; ?>
                
                <?php if ($is_stock_manager || $is_admin): ?>
                <a href="products.php" class="btn btn-sm btn-outline-info d-flex align-items-center gap-1">
                    <i class="bx bx-package fs-6"></i>
                    <span>Products</span>
                </a>
                <?php endif; ?>
                
                <?php if ($is_seller || $is_admin): ?>
                <a href="customers.php" class="btn btn-sm btn-outline-dark d-flex align-items-center gap-1">
                    <i class="bx bx-user fs-6"></i>
                    <span>Customers</span>
                </a>
                <?php endif; ?>
                
                <?php if ($is_field_executive || $is_admin): ?>
                <a href="store_visit_form.php" class="btn btn-sm btn-outline-purple d-flex align-items-center gap-1">
                    <i class="bx bx-car fs-6"></i>
                    <span>Store Visit</span>
                </a>
                <?php endif; ?>
                
                <?php if ($is_seller || $is_admin): ?>
                <a href="return_management.php" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1">
                    <i class="bx bx-undo fs-6"></i>
                    <span>Returns</span>
                </a>
                <?php endif; ?>
                
                <?php if ($cloud_renewal_notification): ?>
                <a href="cloud_renewal.php" class="btn btn-sm btn-outline-<?= $cloud_days_left <= 3 ? 'danger' : 'warning' ?> d-flex align-items-center gap-1">
                    <i class="bx bx-cloud fs-6"></i>
                    <span>Renew Cloud</span>
                    <span class="badge bg-<?= $cloud_days_left <= 3 ? 'danger' : 'warning' ?> ms-1">
                        <?= $cloud_days_left ?>
                    </span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
                <!-- Quick Stats Cards -->
                <div class="row g-3 mb-4">
                    <?php if ($is_seller || $is_admin): ?>
                    <!-- Sales Metrics with Returns -->
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-primary border-3 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Today's Net Revenue</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($today_net_revenue, 0) ?></h3>
                                        <small class="text-muted">
                                            <i class="bx bx-trending-up text-success me-1"></i>
                                            <?= $today_gross_sales ?> sales
                                            <?php if ($today_returns > 0): ?>
                                            <br>
                                           
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="avatar-sm flex-shrink-0">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($yesterday_net_revenue > 0): ?>
                                <div class="mt-3">
                                    <?php 
                                    $growth = $yesterday_net_revenue > 0 ? (($today_net_revenue - $yesterday_net_revenue) / $yesterday_net_revenue * 100) : 0;
                                    $class = $growth >= 0 ? 'text-success' : 'text-danger';
                                    ?>
                                    <small class="<?= $class ?>">
                                        <i class="bx bx-<?= $growth >= 0 ? 'up-arrow-alt' : 'down-arrow-alt' ?> me-1"></i>
                                        <?= number_format(abs($growth), 1) ?>% from yesterday
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-success border-3 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Monthly Net Revenue</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($month_net_revenue, 0) ?></h3>
                                        <small class="text-muted">
                                            <i class="bx bx-calendar me-1"></i>
                                            <?= $month_gross_sales ?> sales this month
                                            <?php if ($month_returns > 0): ?>
                                            <br>
                                           
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="avatar-sm flex-shrink-0">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-trending-up text-success"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-warning border-3 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Pending Invoices</h6>
                                        <h3 class="mb-0 text-warning"><?= $pending_invoices ?></h3>
                                        <small class="text-muted">
                                            ₹<?= number_format($pending_invoices_amount, 0) ?> pending amount
                                        </small>
                                    </div>
                                    <div class="avatar-sm flex-shrink-0">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-time text-warning"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-hover border-start border-info border-3 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-1">Active Customers</h6>
                                        <h3 class="mb-0 text-info"><?= $active_customers ?></h3>
                                        <small class="text-muted">
                                            <i class="bx bx-user-check me-1"></i>
                                            Last 30 days
                                        </small>
                                    </div>
                                    <div class="avatar-sm flex-shrink-0">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-user-circle text-info"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Stock Metrics Row -->
                <?php if ($is_stock_manager || $is_admin): ?>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h6 class="mb-3 text-muted">Stock Overview</h6>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <div class="card bg-light border-0">
                                            <div class="card-body text-center">
                                                <h2 class="text-primary mb-1">₹<?= number_format($shop_stock_value, 0) ?></h2>
                                                <small class="text-muted">Total Stock Value</small>
                                            </div>
                                        </div>
                                    </div>
                                    <a href="products.php?search=&category=&hsn=&stock=low" class="col-md-3">
                                        <div class="card bg-light border-0">
                                            <div class="card-body text-center">
                                                <h2 class="<?= $low_stock_items > 0 ? 'text-warning' : 'text-success' ?> mb-1"><?= $low_stock_items ?></h2>
                                                <small class="text-muted">Low Stock Items</small>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="products.php?search=&category=&hsn=&stock=out" class="col-md-3">
                                        <div class="card bg-light border-0">
                                            <div class="card-body text-center">
                                                <h2 class="<?= $out_of_stock > 0 ? 'text-danger' : 'text-success' ?> mb-1"><?= $out_of_stock ?></h2>
                                                <small class="text-muted">Out of Stock</small>
                                            </div>
                                        </div>
                                    </a>
                                    <a href="products.php" class="col-md-3">
                                        <div class="card bg-light border-0">
                                            <div class="card-body text-center">
                                                <h2 class="text-info mb-1"><?= $total_products ?></h2>
                                                <small class="text-muted">Active Products</small>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Charts and Recent Activity -->
                <div class="row g-3 mb-4">
                    <!-- Sales Chart (Net Revenue) -->
                    <div class="col-xl-8">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="card-title mb-0">Sales Performance (Net of Returns)</h5>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                            Last 6 Months
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#">Last 3 Months</a></li>
                                            <li><a class="dropdown-item" href="#">Last 6 Months</a></li>
                                            <li><a class="dropdown-item" href="#">This Year</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <div style="position: relative; height: 250px;">
                                    <canvas id="salesChart"></canvas>
                                </div>
                                <?php if ($month_returns > 0): ?>
                                <div class="mt-3 text-center">
                                    <small class="text-muted">
                                        <i class="bx bx-info-circle me-1"></i>
                                        Showing net revenue after deducting returns of ₹<?= number_format($month_returns, 0) ?> this month
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Sales with Returns Indicator -->
                    <div class="col-xl-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="card-title mb-0">Recent Sales</h5>
                                    <?php if ($today_returns > 0): ?>
                                    <span class="badge bg-warning">
                                        <i class="bx bx-undo me-1"></i> Returns: ₹<?= number_format($today_returns, 0) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="list-group list-group-flush">
                                    <?php if (!empty($recent_sales)): ?>
                                        <?php foreach ($recent_sales as $sale): 
                                            $has_returns = $sale['returns'] > 0;
                                        ?>
                                        <div class="list-group-item border-0 px-0 py-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-0"><?= htmlspecialchars($sale['invoice_number']) ?></h6>
                                                    <small class="text-muted"><?= htmlspecialchars($sale['customer']) ?></small>
                                                    <?php if ($has_returns): ?>
                                                    <div>
                                                        <small class="text-danger">
                                                            <i class="bx bx-undo me-1"></i> Returns: ₹<?= number_format($sale['returns'], 0) ?>
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-end">
                                                    <span class="fw-bold <?= $has_returns ? 'text-success' : '' ?>">
                                                        ₹<?= number_format($sale['net_total'], 0) ?>
                                                    </span>
                                                    <?php if ($has_returns): ?>
                                                    <div>
                                                        <small class="text-muted">
                                                            <s class="text-danger">₹<?= number_format($sale['gross_total'], 0) ?></s>
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <small class="text-muted"><?= date('h:i A', strtotime($sale['created_at'])) ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-3">
                                            <i class="bx bx-receipt fs-1 text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No recent sales</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-3">
                                    <a href="invoices.php" class="btn btn-outline-primary btn-sm w-100">
                                        View All Invoices <i class="bx bx-chevron-right ms-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions & System Status -->
                <div class="row g-3 mb-4">
                   <div class="col-xl-12">
    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">System Status</h5>
            <div class="d-flex flex-wrap gap-2">
                <!-- Database Status -->
                <div class="d-flex align-items-center gap-2 bg-light rounded-pill px-3 py-2">
                    <span class="badge bg-success rounded-circle p-1">
                        <i class="bx bx-server fs-6"></i>
                    </span>
                    <span class="fw-medium">Database</span>
                    <span class="badge bg-success">Online</span>
                </div>
                
                <!-- Cloud Status -->
                <div class="d-flex align-items-center gap-2 bg-light rounded-pill px-3 py-2">
                    <span class="badge <?= $cloud_renewal_notification ? ($cloud_days_left <= 3 ? 'bg-danger' : 'bg-warning') : 'bg-success' ?> rounded-circle p-1">
                        <i class="bx bx-cloud fs-6"></i>
                    </span>
                    <span class="fw-medium">Cloud</span>
                    <?php if ($cloud_renewal_notification): ?>
                    <span class="badge bg-<?= $cloud_days_left <= 3 ? 'danger' : 'warning' ?>">
                        <?= $cloud_days_left ?>d
                    </span>
                    <?php else: ?>
                    <span class="badge bg-success">Active</span>
                    <?php endif; ?>
                </div>
                
                <!-- Server Time -->
                <div class="d-flex align-items-center gap-2 bg-light rounded-pill px-3 py-2">
                    <span class="badge bg-info rounded-circle p-1">
                        <i class="bx bx-time fs-6"></i>
                    </span>
                    <span class="fw-medium">Time</span>
                    <small class="text-muted"><?= date('h:i A') ?></small>
                </div>
                
                <!-- Memory Usage -->
                <div class="d-flex align-items-center gap-2 bg-light rounded-pill px-3 py-2">
                    <span class="badge bg-warning rounded-circle p-1">
                        <i class="bx bx-data fs-6"></i>
                    </span>
                    <span class="fw-medium">Memory</span>
                    <small class="text-muted"><?= round(memory_get_usage()/1024/1024, 2) ?> MB</small>
                </div>
                
               
                
                <!-- Cloud Expiry -->
                <div class="d-flex align-items-center gap-2 bg-light rounded-pill px-3 py-2">
                    <span class="badge bg-dark rounded-circle p-1">
                        <i class="bx bx-calendar fs-6"></i>
                    </span>
                    <span class="fw-medium">Expiry</span>
                    <small class="text-muted">
                        <?= $cloud_expiry_date ? date('d M Y', strtotime($cloud_expiry_date)) : 'N/A' ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
                    
                </div>

                <!-- Additional Metrics for Admin -->
                <?php if ($is_admin): ?>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Financial Overview</h5>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="card border-start border-danger border-3">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="text-muted mb-1">Today's Expenses</h6>
                                                        <h3 class="mb-0 text-danger">₹<?= number_format($today_expenses, 0) ?></h3>
                                                    </div>
                                                    <div class="avatar-sm flex-shrink-0">
                                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                                            <i class="bx bx-money text-danger"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="card border-start border-info border-3">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="text-muted mb-1">Pending Transfers</h6>
                                                        <h3 class="mb-0 text-info"><?= $pending_transfers ?></h3>
                                                        <small class="text-muted">stock transfers</small>
                                                    </div>
                                                    <div class="avatar-sm flex-shrink-0">
                                                        <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                                            <i class="bx bx-transfer text-info"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="card border-start border-dark border-3">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="text-muted mb-1">Pending Payments</h6>
                                                        <h3 class="mb-0"><?= $pending_payments ?></h3>
                                                        <small class="text-muted">to suppliers</small>
                                                    </div>
                                                    <div class="avatar-sm flex-shrink-0">
                                                        <span class="avatar-title bg-dark bg-opacity-10 rounded-circle fs-3">
                                                            <i class="bx bx-credit-card"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($today_returns > 0 || $month_returns > 0): ?>
                                <div class="row g-3 mt-3">
                                    <div class="col-md-6">
                                        <div class="alert alert-warning alert-dismissible fade show mb-0" role="alert">
                                            <h6 class="alert-heading">
                                                <i class="bx bx-undo me-2"></i> Returns Summary
                                            </h6>
                                            <div class="row">
                                                <div class="col-6">
                                                    <small>Today: ₹<?= number_format($today_returns, 0) ?></small>
                                                    <div class="progress mt-1" style="height: 5px;">
                                                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?= min($today_return_percentage, 100) ?>%"></div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <small>This Month: ₹<?= number_format($month_returns, 0) ?></small>
                                                    <div class="progress mt-1" style="height: 5px;">
                                                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?= min($month_return_percentage, 100) ?>%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="alert alert-success alert-dismissible fade show mb-0" role="alert">
                                            <h6 class="alert-heading">
                                                <i class="bx bx-trending-up me-2"></i> Net Performance
                                            </h6>
                                            <div class="row">
                                                <div class="col-6">
                                                    <small>Today's Net: ₹<?= number_format($today_net_revenue, 0) ?></small>
                                                    <br>
                                                    <span class="text-success">
                                                        <?= number_format(100 - $today_return_percentage, 1) ?>% of gross
                                                    </span>
                                                </div>
                                                <div class="col-6">
                                                    <small>Month's Net: ₹<?= number_format($month_net_revenue, 0) ?></small>
                                                    <br>
                                                    <span class="text-success">
                                                        <?= number_format(100 - $month_return_percentage, 1) ?>% of gross
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Field Executive Section -->
                <?php if ($is_field_executive || ($is_admin && $pending_requirements > 0)): ?>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card shadow-sm border-start border-success border-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="card-title mb-1">Pending Requirements</h5>
                                        <p class="text-muted mb-0">Action needed for store requirements</p>
                                    </div>
                                    <div class="text-end">
                                        <h2 class="text-success mb-0"><?= $pending_requirements ?></h2>
                                        <a href="store_requirements.php" class="btn btn-success btn-sm mt-2">
                                            <i class="bx bx-check-circle me-1"></i> Review Now
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Enhanced Chart with Net Revenue (after returns)
const trendMonths = <?= json_encode(array_column($trend, 'month')) ?>;
const trendNetRevenue = <?= json_encode(array_column($trend, 'net')) ?>;
const trendReturns = <?= json_encode(array_column($trend, 'returns')) ?>;

const ctx = document.getElementById('salesChart').getContext('2d');

// Create gradients
const gradientNet = ctx.createLinearGradient(0, 0, 0, 400);
gradientNet.addColorStop(0, 'rgba(91, 115, 232, 0.3)');
gradientNet.addColorStop(1, 'rgba(91, 115, 232, 0.05)');

const gradientReturns = ctx.createLinearGradient(0, 0, 0, 400);
gradientReturns.addColorStop(0, 'rgba(255, 193, 7, 0.3)');
gradientReturns.addColorStop(1, 'rgba(255, 193, 7, 0.05)');

const salesChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: trendMonths,
        datasets: [{
            label: 'Net Revenue',
            data: trendNetRevenue,
            backgroundColor: gradientNet,
            borderColor: '#5b73e8',
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#5b73e8',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
        },
        {
            label: 'Returns',
            data: trendReturns,
            backgroundColor: gradientReturns,
            borderColor: '#ffc107',
            borderWidth: 2,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#ffc107',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            hidden: trendReturns.every(v => v === 0) // Hide if no returns
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    padding: 15,
                    usePointStyle: true
                }
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleFont: {
                    size: 12
                },
                bodyFont: {
                    size: 13
                },
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        let value = context.parsed.y;
                        if (label === 'Net Revenue') {
                            return 'Net Revenue: ₹' + value.toLocaleString('en-IN');
                        } else if (label === 'Returns') {
                            return 'Returns: ₹' + value.toLocaleString('en-IN');
                        }
                        return label + ': ₹' + value.toLocaleString('en-IN');
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)',
                    drawBorder: false
                },
                ticks: {
                    font: {
                        size: 11
                    },
                    callback: function(value) {
                        return '₹' + (value/1000).toFixed(0) + 'K';
                    },
                    padding: 10
                },
                title: {
                    display: true,
                    text: 'Amount (₹)',
                    font: {
                        size: 12
                    }
                }
            },
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    font: {
                        size: 11
                    },
                    padding: 10
                }
            }
        },
        interaction: {
            intersect: false,
            mode: 'index'
        },
        elements: {
            line: {
                cubicInterpolationMode: 'monotone'
            }
        }
    }
});

// Cloud Timer Countdown Function with End of Day Calculation
function updateCloudTimer() {
    <?php if ($show_timer_in_header && $expiry_timestamp_end_of_day): ?>
    // Calculate time left until end of day on expiry date
    const expiryTimestamp = <?= $expiry_timestamp_end_of_day ?> * 1000; // Convert to milliseconds
    const currentTimestamp = Date.now();
    let remainingSeconds = Math.floor((expiryTimestamp - currentTimestamp) / 1000);
    
    if (remainingSeconds <= 0) {
        // Time's up - redirect to renewal page
        window.location.href = 'cloud_renewal.php';
        return;
    }
    
    const days = Math.floor(remainingSeconds / (24 * 60 * 60));
    const hours = Math.floor((remainingSeconds % (24 * 60 * 60)) / (60 * 60));
    const minutes = Math.floor((remainingSeconds % (60 * 60)) / 60);
    const seconds = remainingSeconds % 60;
    
    // Update header timer
    const headerTimer = document.getElementById('headerTimer');
    if (headerTimer) {
        headerTimer.querySelector('.days').textContent = days.toString().padStart(2, '0');
        headerTimer.querySelector('.hours').textContent = hours.toString().padStart(2, '0');
        headerTimer.querySelector('.minutes').textContent = minutes.toString().padStart(2, '0');
        headerTimer.querySelector('.seconds').textContent = seconds.toString().padStart(2, '0');
    }
    
    // Update modal timer if exists
    const modalDays = document.getElementById('modalDaysLeft');
    if (modalDays) {
        modalDays.textContent = days;
    }
    
    // Update modal timer display
    const modalDaysElement = document.querySelector('.timer-box .days');
    const modalHoursElement = document.querySelector('.timer-box .hours');
    const modalMinutesElement = document.querySelector('.timer-box .minutes');
    const modalSecondsElement = document.querySelector('.timer-box .seconds');
    
    if (modalDaysElement) modalDaysElement.textContent = days.toString().padStart(2, '0');
    if (modalHoursElement) modalHoursElement.textContent = hours.toString().padStart(2, '0');
    if (modalMinutesElement) modalMinutesElement.textContent = minutes.toString().padStart(2, '0');
    if (modalSecondsElement) modalSecondsElement.textContent = seconds.toString().padStart(2, '0');
    
    // Update progress bar
    const progressBar = document.querySelector('.progress-bar');
    if (progressBar) {
        const progressPercent = ((30 - days) / 30) * 100;
        progressBar.style.width = `${progressPercent}%`;
        
        // Change color based on urgency
        if (days <= 3) {
            progressBar.classList.remove('bg-warning', 'bg-info');
            progressBar.classList.add('bg-danger');
        } else if (days <= 7) {
            progressBar.classList.remove('bg-danger', 'bg-info');
            progressBar.classList.add('bg-warning');
        } else {
            progressBar.classList.remove('bg-danger', 'bg-warning');
            progressBar.classList.add('bg-info');
        }
    }
    <?php endif; ?>
}

// Initialize timer
updateCloudTimer();
setInterval(updateCloudTimer, 1000);

// Show 1-month modal on page load
<?php if ($show_one_month_modal): ?>
document.addEventListener('DOMContentLoaded', function() {
    const oneMonthModal = new bootstrap.Modal(document.getElementById('oneMonthModal'));
    oneMonthModal.show();
});
<?php endif; ?>

// Add hover effect to cards
document.querySelectorAll('.card-hover').forEach(card => {
    card.addEventListener('mouseenter', function() {
        this.style.transform = 'translateY(-2px)';
        this.style.transition = 'all 0.2s ease';
    });
    
    card.addEventListener('mouseleave', function() {
        this.style.transform = 'translateY(0)';
    });
});

// Auto-refresh dashboard every 60 seconds (optional)
setTimeout(function() {
    window.location.reload();
}, 60000);

// Add CSS animations for timer
const style = document.createElement('style');
style.textContent = `
@keyframes pulseWarning {
    0% {
        box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(255, 193, 7, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
    }
}

@keyframes pulseDanger {
    0% {
        box-shadow: 0 0 0 0 rgba(249, 49, 84, 0.7);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(249, 49, 84, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(249, 49, 84, 0);
    }
}

@keyframes float {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}

/* Timer Styles */
.cloud-timer-container {
    backdrop-filter: blur(10px);
}

.timer-segment {
    display: inline-block;
    text-align: center;
    min-width: 40px;
}

.timer-value {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
    line-height: 1;
}

.timer-label {
    font-size: 0.75rem;
    opacity: 0.8;
}

.timer-box {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 10px 5px;
    min-width: 70px;
    text-align: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.timer-box span {
    display: block;
    font-size: 1.8rem;
    font-weight: 700;
    color: white;
    line-height: 1;
}

.timer-box small {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.8);
}

/* Modal Timer */
.modal .timer-box {
    background: rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(0, 0, 0, 0.2);
}

.modal .timer-box span {
    color: #333;
}

.modal .timer-box small {
    color: #666;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .timer-segment {
        min-width: 30px;
    }
    
    .timer-value {
        font-size: 1.2rem;
    }
    
    .timer-box {
        min-width: 50px;
        padding: 8px 3px;
    }
    
    .timer-box span {
        font-size: 1.4rem;
    }
    
    .timer-box small {
        font-size: 0.7rem;
    }
    
    .cloud-timer-container {
        padding: 15px !important;
    }
    
    .timer-countdown .d-flex {
        flex-wrap: wrap;
        justify-content: center !important;
    }
    
    .timer-segment {
        margin: 0 2px;
    }
}

@media (max-width: 576px) {
    .cloud-timer-container {
        text-align: center;
    }
    
    .cloud-timer-container .d-flex {
        flex-direction: column;
        align-items: center;
    }
    
    .cloud-timer-container .text-end {
        text-align: center !important;
        margin-top: 10px;
    }
    
    .timer-countdown {
        margin-bottom: 10px;
    }
    
    .timer-segment {
        margin: 0 3px;
    }
}
`;
document.head.appendChild(style);
</script>

<style>
/* Custom Styles */
.bg-gradient-primary {
    background: linear-gradient(135deg, #5b73e8 0%, #8b9cea 100%) !important;
}

.bg-gradient-warning {
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%) !important;
}

.bg-gradient-danger {
    background: linear-gradient(135deg, #f93154 0%, #ff6b6b 100%) !important;
}

.bg-purple {
    background-color: #6f42c1 !important;
}

.text-purple {
    color: #6f42c1 !important;
}

.card-hover {
    transition: all 0.3s ease;
}

.card-hover:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.border-start {
    border-left-width: 4px !important;
}

.avatar-sm {
    width: 40px;
    height: 40px;
}

.avatar-lg {
    width: 70px;
    height: 70px;
}

.avatar-title {
    display: flex;
    align-items: center;
    justify-content: center;
}

.list-group-item:first-child {
    border-top: 0;
}

.list-group-item:last-child {
    border-bottom: 0;
}

/* Timer urgent styles */
.timer-urgent {
    animation: pulseDanger 1s infinite;
}

.timer-warning {
    animation: pulseWarning 2s infinite;
}

/* Cloud timer specific styles */
.cloud-timer-container .timer-value {
    font-family: 'Courier New', monospace;
}

.timer-countdown .timer-segment {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 5px;
    padding: 5px;
    min-width: 45px;
}

.timer-countdown .timer-value {
    font-size: 1.8rem;
    font-weight: 700;
    font-family: 'Courier New', monospace;
}

.timer-countdown .timer-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Mobile responsive adjustments */
@media (max-width: 768px) {
    .avatar-lg {
        width: 50px;
        height: 50px;
    }
    
    .avatar-lg i {
        font-size: 1.5rem !important;
    }
    
    .display-4 {
        font-size: 2rem;
    }
    
    .card-body {
        padding: 1rem !important;
    }
    
    h3 {
        font-size: 1.5rem !important;
    }
    
    .cloud-notification .btn {
        margin-top: 10px;
        width: 100%;
    }
    
    /* Welcome header adjustments */
    .welcome-header h3 {
        font-size: 1.3rem !important;
    }
    
    .welcome-header p {
        font-size: 0.85rem !important;
    }
    
    .cloud-timer-container {
        margin-top: 15px;
    }
    
    /* Timer in header mobile view */
    .timer-countdown .d-flex {
        justify-content: center !important;
    }
    
    .timer-countdown .timer-segment {
        min-width: 35px;
    }
    
    .timer-countdown .timer-value {
        font-size: 1.4rem;
    }
}

@media (max-width: 576px) {
    /* Stack timer elements vertically on very small screens */
    .timer-countdown .d-flex {
        flex-wrap: wrap;
    }
    
    .timer-segment {
        margin-bottom: 5px;
    }
    
    /* Adjust modal for mobile */
    .modal-dialog {
        margin: 10px;
    }
    
    .modal-body {
        padding: 15px !important;
    }
    
    .timer-box {
        min-width: 60px;
        margin-bottom: 10px;
    }
    
    /* Cloud timer mobile */
    .cloud-timer-container .d-flex {
        flex-direction: column;
    }
    
    .cloud-timer-container .text-end {
        margin-top: 15px;
        text-align: center !important;
    }
    
    .cloud-timer-container .timer-countdown {
        margin-bottom: 15px;
    }
}
</style>
</body>
</html>