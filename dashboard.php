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
$show_one_month_modal = false;
if ($one_month_before && !isset($_COOKIE['cloud_one_month_shown'])) {
    $show_one_month_modal = true;
    setcookie('cloud_one_month_shown', '1', time() + (86400 * 30), '/');
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
$pending_invoices_count = $pending_invoices_amount = $pending_payments = $pending_requirements = $active_customers = 0;
$total_products = $out_of_stock = 0;
$recent_sales = [];
$trend = [];

// Manufacturer outstanding stats
$total_supplier_outstanding = 0;
$total_credit = 0;
$total_debit = 0;
$total_purchase_paid = 0;

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
    $today_gross_sales = (int)($todayData['cnt'] ?? 0);
    $today_gross_revenue = (float)($todayData['gross_rev'] ?? 0);
    $today_returns = (float)($todayData['returns'] ?? 0);
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
    $yesterday_gross_sales = (int)($yesterdayData['cnt'] ?? 0);
    $yesterday_gross_revenue = (float)($yesterdayData['gross_rev'] ?? 0);
    $yesterday_returns = (float)($yesterdayData['returns'] ?? 0);
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
    $month_gross_sales = (int)($monthData['cnt'] ?? 0);
    $month_gross_revenue = (float)($monthData['gross_rev'] ?? 0);
    $month_returns = (float)($monthData['returns'] ?? 0);
    $month_net_revenue = $month_gross_revenue - $month_returns;

    // 4. Pending Invoices - EXACTLY like invoices.php
    $sql = "SELECT
                COUNT(*) as count,
                COALESCE(SUM(i.pending_amount), 0) as pending
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
    $pending_invoice_data = $stmt->fetch();
    $pending_invoices_count = (int)($pending_invoice_data['count'] ?? 0);
    $pending_invoices_amount = (float)($pending_invoice_data['pending'] ?? 0);

    // 5. Current Stock Value (for current shop only)
    $sql = "SELECT COALESCE(SUM(ps.quantity * p.stock_price), 0)
            FROM product_stocks ps
            JOIN products p ON ps.product_id = p.id
            WHERE ps.business_id = ?
              AND p.is_active = 1
              AND ps.shop_id = ?";
    $params = [$current_business_id, $current_shop_id];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $shop_stock_value = (float)$stmt->fetchColumn();

    // 6. Low Stock Items (for current shop only)
    $sql = "SELECT COUNT(DISTINCT p.id)
            FROM products p
            LEFT JOIN product_stocks ps ON p.id = ps.product_id 
                AND ps.shop_id = ?
            WHERE p.is_active = 1
              AND p.business_id = ?
              AND ps.quantity IS NOT NULL
              AND ps.quantity < p.min_stock_level
              AND ps.quantity > 0";
    $params = [$current_shop_id, $current_business_id];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $low_stock_items = (int)$stmt->fetchColumn();

    // 7. Out of Stock (for current shop only)
    $sql = "SELECT COUNT(DISTINCT p.id)
            FROM products p
            LEFT JOIN product_stocks ps ON p.id = ps.product_id 
                AND ps.shop_id = ?
            WHERE p.is_active = 1
              AND p.business_id = ?
              AND (ps.quantity IS NULL OR ps.quantity = 0)";
    $params = [$current_shop_id, $current_business_id];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out_of_stock = (int)$stmt->fetchColumn();

    // 8. Total Active Products
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE is_active = 1 AND business_id = ?");
    $stmt->execute([$current_business_id]);
    $total_products = (int)$stmt->fetchColumn();

    // 9. Today's Expenses
    $sql = "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date = ? AND business_id = ?";
    $params = [$today, $current_business_id];
    if (!$is_admin) {
        $sql .= " AND shop_id = ?";
        $params[] = $current_shop_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $today_expenses = (float)$stmt->fetchColumn();

    // 10. Active Customers
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
    $active_customers = (int)$stmt->fetchColumn();

    // 11. Pending Requirements (for field executives)
    if ($is_field_executive || $is_admin) {
        if ($is_field_executive) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM store_requirements WHERE requirement_status = 'pending' AND business_id = ? AND field_executive_id = ?");
            $stmt->execute([$current_business_id, $user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM store_requirements WHERE requirement_status = 'pending' AND business_id = ?");
            $stmt->execute([$current_business_id]);
        }
        $pending_requirements = (int)$stmt->fetchColumn();
    }

    // 12. Purchase Stats - EXACTLY like purchases.php
    $sql = "SELECT 
                COALESCE(SUM(total_amount - paid_amount), 0) as pending_amount,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COUNT(*) as total_orders,
                SUM(CASE WHEN payment_status IN ('unpaid', 'partial') THEN 1 ELSE 0 END) as pending_orders
            FROM purchases 
            WHERE business_id = ?";
    $params = [$current_business_id];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $purchase_stats = $stmt->fetch();
    $pending_payments = (float)($purchase_stats['pending_amount'] ?? 0);
    $total_paid_purchases = (float)($purchase_stats['total_paid'] ?? 0);
    $total_purchase_orders = (int)($purchase_stats['total_orders'] ?? 0);
    $pending_purchase_orders = (int)($purchase_stats['pending_orders'] ?? 0);

    // 13. Total Supplier Outstanding - EXACTLY like manufacturers.php
    $manufacturer_sql = "
        SELECT 
            m.id,
            m.initial_outstanding_amount,
            m.initial_outstanding_type,
            COALESCE((SELECT SUM(total_amount - paid_amount) FROM purchases WHERE manufacturer_id = m.id AND payment_status != 'paid'), 0) as purchase_balance
        FROM manufacturers m
        WHERE m.business_id = ? AND m.is_active = 1
    ";
    $stmt = $pdo->prepare($manufacturer_sql);
    $stmt->execute([$current_business_id]);
    $manufacturers = $stmt->fetchAll();
    
    $total_supplier_outstanding = 0;
    $total_credit = 0;
    $total_debit = 0;
    
    foreach ($manufacturers as $m) {
        $purchase_balance = (float)($m['purchase_balance'] ?? 0);
        $initial_outstanding = (float)($m['initial_outstanding_amount'] ?? 0);
        $initial_type = $m['initial_outstanding_type'] ?? 'none';
        
        // Calculate net outstanding based on type
        if ($initial_type === 'credit') {
            // Credit: Supplier owes us - reduces what we owe
            $net = max(0, $purchase_balance - $initial_outstanding);
            $total_credit += $initial_outstanding;
        } elseif ($initial_type === 'debit') {
            // Debit: We owe supplier - increases what we owe
            $net = $purchase_balance + $initial_outstanding;
            $total_debit += $initial_outstanding;
        } else {
            $net = $purchase_balance;
        }
        $total_supplier_outstanding += $net;
    }

    // 14. Pending Stock Transfers
    $sql = "SELECT COUNT(*) FROM stock_transfers WHERE status IN ('pending', 'approved', 'in_transit') AND business_id = ?";
    $params = [$current_business_id];
    if (!$is_admin) {
        $sql .= " AND (from_shop_id = ? OR to_shop_id = ?)";
        $params = array_merge($params, [$current_shop_id, $current_shop_id]);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pending_transfers = (int)$stmt->fetchColumn();

    // 15. Recent Sales
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

    // 16. Monthly Trend
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
<style>
/* Invoice-style cards - no borders, only shadow */
.card {
    border: none !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    height: calc(100% - 1rem);
    margin-bottom: 1rem;
}

.card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.card-body {
    display: flex;
    flex-direction: column;
    padding: 1.25rem;
}

/* Stats card specific styles */
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.stats-card .text-muted {
    color: rgba(255,255,255,0.8) !important;
}

/* Avatar styles from invoices.php */
.avatar-sm {
    width: 48px;
    height: 48px;
    flex-shrink: 0;
}

.avatar-title {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}

/* Badge styles */
.badge.bg-opacity-10 {
    opacity: 0.9;
}

/* Gradient backgrounds */
.bg-gradient-primary {
    background: linear-gradient(135deg, #5b73e8 0%, #8b9cea 100%) !important;
}

.bg-gradient-success {
    background: linear-gradient(135deg, #28a745 0%, #34ce57 100%) !important;
}

.bg-gradient-warning {
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%) !important;
}

.bg-gradient-danger {
    background: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%) !important;
}

.bg-gradient-info {
    background: linear-gradient(135deg, #17a2b8 0%, #3ab9d1 100%) !important;
}

.bg-gradient-purple {
    background: linear-gradient(135deg, #6f42c1 0%, #9b6fe0 100%) !important;
}

/* Purple button */
.btn-outline-purple {
    color: #6f42c1;
    border-color: #6f42c1;
}

.btn-outline-purple:hover {
    color: #fff;
    background-color: #6f42c1;
    border-color: #6f42c1;
}

/* Link styling */
a.text-decoration-none {
    text-decoration: none !important;
}

a.text-decoration-none .card {
    color: inherit;
}

/* Timer styles */
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

/* List group */
.list-group-item:first-child {
    border-top: 0;
}

.list-group-item:last-child {
    border-bottom: 0;
}

/* Mobile responsive */
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
    
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
    
    h3 {
        font-size: 1.3rem !important;
    }
    
    h2 {
        font-size: 1.6rem !important;
    }
}

@media (max-width: 576px) {
    .timer-countdown .d-flex {
        flex-wrap: wrap;
    }
    
    .timer-segment {
        margin-bottom: 5px;
    }
    
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
}
</style>
</head>
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

                <!-- Modern Welcome Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card bg-gradient-primary text-white shadow-sm overflow-hidden">
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
                                                <h3 class="mb-1">Welcome back, <?= htmlspecialchars($user_name) ?>!</h3>
                                                <p class="mb-0 opacity-75">
                                                    <i class="bx bx-building me-1"></i> <?= htmlspecialchars($current_business_name) ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="bx bx-store me-1"></i> <?= htmlspecialchars($current_shop_name) ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="bx bx-calendar me-1"></i> <?= date('l, F j, Y') ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="bx bx-time me-1"></i> <?= date('h:i A') ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($show_timer_in_header): ?>
                                    <div class="col-md-6 mt-3 mt-md-0">
                                        <div class="bg-white bg-opacity-10 rounded-3 p-3 border border-white border-opacity-25">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm rounded-circle bg-white bg-opacity-25 p-2 me-3">
                                                        <i class="bx bx-cloud fs-4"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-0">Cloud Expires in</h6>
                                                        <p class="mb-0 small opacity-75">
                                                            Plan: <?= htmlspecialchars($cloud_plan) ?> | 
                                                            Date: <?= date('d M Y', strtotime($cloud_expiry_date)) ?>
                                                        </p>
                                                        <p class="mb-0 small opacity-75">
                                                            <i class="bx bx-time me-1"></i> Until 11:59 PM
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <div class="timer-countdown" id="headerTimer">
                                                        <div class="d-flex justify-content-end">
                                                            <div class="timer-segment me-1">
                                                                <span class="timer-value days"><?= str_pad($cloud_days_left, 2, '0', STR_PAD_LEFT) ?></span>
                                                                <small class="timer-label">D</small>
                                                            </div>
                                                            <div class="timer-segment me-1">
                                                                <span class="timer-value hours">00</span>
                                                                <small class="timer-label">H</small>
                                                            </div>
                                                            <div class="timer-segment me-1">
                                                                <span class="timer-value minutes">00</span>
                                                                <small class="timer-label">M</small>
                                                            </div>
                                                            <div class="timer-segment">
                                                                <span class="timer-value seconds">00</span>
                                                                <small class="timer-label">S</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <a href="cloud_renewal.php" class="btn btn-sm btn-light mt-2 px-3">
                                                        <i class="bx bx-sync me-1"></i> Renew
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Quick Actions</h5>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ($is_seller || $is_admin): ?>
                                    <a href="pos.php" class="btn btn-sm btn-outline-primary">
                                        <i class="bx bx-plus me-1"></i>New Sale
                                    </a>
                                    <a href="invoices.php" class="btn btn-sm btn-outline-success">
                                        <i class="bx bx-receipt me-1"></i>Invoices
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_stock_manager || $is_admin): ?>
                                    <a href="stock_transfers.php" class="btn btn-sm btn-outline-warning">
                                        <i class="bx bx-transfer me-1"></i>Stock Transfer
                                    </a>
                                    <a href="products.php" class="btn btn-sm btn-outline-info">
                                        <i class="bx bx-package me-1"></i>Products
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_admin || $is_shop_manager): ?>
                                    <a href="manufacturers.php" class="btn btn-sm btn-outline-purple">
                                        <i class="bx bx-buildings me-1"></i>Suppliers
                                    </a>
                                    <a href="purchases.php" class="btn btn-sm btn-outline-secondary">
                                        <i class="bx bx-shopping-bag me-1"></i>Purchases
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_seller || $is_admin): ?>
                                    <a href="customers.php" class="btn btn-sm btn-outline-dark">
                                        <i class="bx bx-user me-1"></i>Customers
                                    </a>
                                    <a href="return_management.php" class="btn btn-sm btn-outline-danger">
                                        <i class="bx bx-undo me-1"></i>Returns
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($cloud_renewal_notification): ?>
                                    <a href="cloud_renewal.php" class="btn btn-sm btn-outline-<?= $cloud_days_left <= 3 ? 'danger' : 'warning' ?>">
                                        <i class="bx bx-cloud me-1"></i>Renew Cloud
                                        <span class="badge bg-<?= $cloud_days_left <= 3 ? 'danger' : 'warning' ?> ms-1"><?= $cloud_days_left ?></span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards - Invoice Style -->
                <div class="row g-3 mb-4">
                    <!-- Today's Net Revenue -->
                    <div class="col-xl-3 col-md-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="text-muted mb-1">Today's Net Revenue</h6>
                                        <h3 class="mb-0 text-primary">₹<?= number_format($today_net_revenue, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-rupee text-primary"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-auto">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1">
                                                <i class="bx bx-trending-up me-1"></i><?= $today_gross_sales ?> sales
                                            </span>
                                            <?php if ($today_returns > 0): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1 ms-1">
                                                <i class="bx bx-undo me-1"></i>₹<?= number_format($today_returns, 0) ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($yesterday_net_revenue > 0): ?>
                                        <?php 
                                        $growth = $yesterday_net_revenue > 0 ? (($today_net_revenue - $yesterday_net_revenue) / $yesterday_net_revenue * 100) : 0;
                                        $class = $growth >= 0 ? 'success' : 'danger';
                                        ?>
                                        <small class="text-<?= $class ?>">
                                            <i class="bx bx-<?= $growth >= 0 ? 'up-arrow-alt' : 'down-arrow-alt' ?>"></i>
                                            <?= number_format(abs($growth), 1) ?>%
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Net Revenue -->
                    <div class="col-xl-3 col-md-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="text-muted mb-1">Monthly Net Revenue</h6>
                                        <h3 class="mb-0 text-success">₹<?= number_format($month_net_revenue, 0) ?></h3>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                            <i class="bx bx-trending-up text-success"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-auto">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-success bg-opacity-10 text-success px-2 py-1">
                                                <i class="bx bx-calendar me-1"></i><?= $month_gross_sales ?> sales
                                            </span>
                                            <?php if ($month_returns > 0): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1 ms-1">
                                                <i class="bx bx-undo me-1"></i>₹<?= number_format($month_returns, 0) ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?= date('F Y') ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Amount - EXACTLY like invoices.php pending stats card -->
                    <div class="col-xl-3 col-md-6">
                        <a href="invoices.php?status=pending" class="text-decoration-none">
                            <div class="card shadow-sm h-100">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="text-muted mb-1">Pending Amount</h6>
                                            <h3 class="mb-0 text-warning">₹<?= number_format($pending_invoices_amount, 0) ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-warning bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-time text-warning"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-auto">
                                        <span class="badge bg-warning bg-opacity-10 text-warning px-2 py-1">
                                            <i class="bx bx-receipt me-1"></i><?= $pending_invoices_count ?> invoice<?= $pending_invoices_count != 1 ? 's' : '' ?> pending
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- Supplier Outstanding -->
                    <div class="col-xl-3 col-md-6">
                        <a href="manufacturers.php?outstanding=has_outstanding" class="text-decoration-none">
                            <div class="card shadow-sm h-100">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="text-muted mb-1">Supplier Outstanding</h6>
                                            <h3 class="mb-0 text-danger">₹<?= number_format($total_supplier_outstanding, 0) ?></h3>
                                        </div>
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                                <i class="bx bx-money text-danger"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-auto">
                                        <?php if ($total_credit > 0): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 me-1">
                                            <i class="bx bx-up-arrow-alt me-1"></i>Credit: ₹<?= number_format($total_credit, 0) ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($total_debit > 0): ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1">
                                            <i class="bx bx-down-arrow-alt me-1"></i>Debit: ₹<?= number_format($total_debit, 0) ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Stock Metrics Row -->
                <?php if ($is_stock_manager || $is_admin): ?>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h6 class="mb-3 text-muted">Stock Overview</h6>
                                <div class="row g-3">
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body text-center">
                                                <h2 class="text-primary mb-1">₹<?= number_format($shop_stock_value, 0) ?></h2>
                                                <small class="text-muted">Total Stock Value</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <a href="products.php?stock=low" class="text-decoration-none">
                                            <div class="card bg-light border-0 h-100">
                                                <div class="card-body text-center">
                                                    <h2 class="<?= $low_stock_items > 0 ? 'text-warning' : 'text-success' ?> mb-1"><?= $low_stock_items ?></h2>
                                                    <small class="text-muted">Low Stock Items</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <a href="products.php?stock=out" class="text-decoration-none">
                                            <div class="card bg-light border-0 h-100">
                                                <div class="card-body text-center">
                                                    <h2 class="<?= $out_of_stock > 0 ? 'text-danger' : 'text-success' ?> mb-1"><?= $out_of_stock ?></h2>
                                                    <small class="text-muted">Out of Stock</small>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <a href="products.php" class="text-decoration-none">
                                            <div class="card bg-light border-0 h-100">
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
                </div>
                <?php endif; ?>

                <!-- Charts and Recent Activity -->
                <div class="row g-3 mb-4">
                    <!-- Sales Chart -->
                    <div class="col-xl-8">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Sales Performance (Net of Returns)</h5>
                                <div style="position: relative; height: 250px;">
                                    <canvas id="salesChart"></canvas>
                                </div>
                                <?php if ($month_returns > 0): ?>
                                <div class="mt-3 text-center">
                                    <small class="text-muted">
                                        <i class="bx bx-info-circle me-1"></i>
                                        Net: ₹<?= number_format($month_net_revenue, 0) ?> | 
                                        Gross: ₹<?= number_format($month_gross_revenue, 0) ?> | 
                                        Returns: ₹<?= number_format($month_returns, 0) ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Sales -->
                    <div class="col-xl-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Recent Sales</h5>
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
                                        <div class="text-center py-4">
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

                <!-- Financial Overview for Admin -->
                <?php if ($is_admin): ?>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Financial Overview</h5>
                                <div class="row g-3">
                                    <!-- Today's Expenses -->
                                    <div class="col-xl-4 col-md-6">
                                        <div class="card shadow-sm h-100">
                                            <div class="card-body d-flex flex-column">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <h6 class="text-muted mb-1">Today's Expenses</h6>
                                                        <h3 class="mb-0 text-danger">₹<?= number_format($today_expenses, 0) ?></h3>
                                                    </div>
                                                    <div class="avatar-sm">
                                                        <span class="avatar-title bg-danger bg-opacity-10 rounded-circle fs-3">
                                                            <i class="bx bx-money text-danger"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="mt-auto">
                                                    <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1">
                                                        <i class="bx bx-calendar me-1"></i><?= date('d M Y') ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Active Customers -->
                                    <div class="col-xl-4 col-md-6">
                                        <a href="customers.php" class="text-decoration-none">
                                            <div class="card shadow-sm h-100">
                                                <div class="card-body d-flex flex-column">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div>
                                                            <h6 class="text-muted mb-1">Active Customers</h6>
                                                            <h3 class="mb-0 text-info"><?= $active_customers ?></h3>
                                                        </div>
                                                        <div class="avatar-sm">
                                                            <span class="avatar-title bg-info bg-opacity-10 rounded-circle fs-3">
                                                                <i class="bx bx-user text-info"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="mt-auto">
                                                        <span class="badge bg-info bg-opacity-10 text-info px-2 py-1">
                                                            <i class="bx bx-time me-1"></i>Last 30 days
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    
                                    <!-- Purchase Stats - EXACTLY like purchases.php -->
                                    <div class="col-xl-4 col-md-6">
                                        <a href="purchases.php?status=partial,unpaid" class="text-decoration-none">
                                            <div class="card shadow-sm h-100">
                                                <div class="card-body d-flex flex-column">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div>
                                                            <h6 class="text-muted mb-1">Supplier Payments</h6>
                                                            <div class="d-flex align-items-baseline gap-2">
                                                                <h3 class="mb-0 text-warning">₹<?= number_format($pending_payments, 0) ?></h3>
                                                                <span class="text-muted">due</span>
                                                            </div>
                                                        </div>
                                                        <div class="avatar-sm">
                                                            <span class="avatar-title bg-success bg-opacity-10 rounded-circle fs-3">
                                                                <i class="bx bx-credit-card text-success"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="mt-auto">
                                                        <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 me-1">
                                                            <i class="bx bx-check-circle me-1"></i>Paid: ₹<?= number_format($total_paid_purchases, 0) ?>
                                                        </span>
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary px-2 py-1">
                                                            <?= $pending_purchase_orders ?> pending orders
                                                        </span>
                                                        <?php 
                                                        $total_purchase_value = $pending_payments + $total_paid_purchases;
                                                        if ($total_purchase_value > 0):
                                                            $paid_percent = ($total_paid_purchases / $total_purchase_value) * 100;
                                                        ?>
                                                        <div class="progress mt-2" style="height: 4px;">
                                                            <div class="progress-bar bg-success" style="width: <?= $paid_percent ?>%"></div>
                                                            <div class="progress-bar bg-warning" style="width: <?= 100 - $paid_percent ?>%"></div>
                                                        </div>
                                                        <div class="mt-1 small d-flex justify-content-between">
                                                            <span class="text-success"><?= number_format($paid_percent, 1) ?>% paid</span>
                                                            <span class="text-warning"><?= number_format(100 - $paid_percent, 1) ?>% due</span>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                </div>

                                <?php if ($today_returns > 0 || $month_returns > 0): ?>
                                <div class="row g-3 mt-3">
                                    <div class="col-md-12">
                                        <div class="alert alert-warning mb-0">
                                            <h6 class="alert-heading">
                                                <i class="bx bx-undo me-2"></i> Returns Summary
                                            </h6>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Today:</span>
                                                        <span class="fw-bold">₹<?= number_format($today_returns, 0) ?> (<?= number_format($today_return_percentage, 1) ?>%)</span>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>This Month:</span>
                                                        <span class="fw-bold">₹<?= number_format($month_returns, 0) ?> (<?= number_format($month_return_percentage, 1) ?>%)</span>
                                                    </div>
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
                <?php if ($is_field_executive && $pending_requirements > 0): ?>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card shadow-sm">
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

                <!-- System Status -->
                <div class="row g-3 mt-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-3">System Status</h5>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="d-flex align-items-center gap-2 bg-light rounded-pill px-3 py-2">
                                        <span class="badge bg-success rounded-circle p-1">
                                            <i class="bx bx-server fs-6"></i>
                                        </span>
                                        <span class="fw-medium">Database</span>
                                        <span class="badge bg-success">Online</span>
                                    </div>
                                    
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
                                    
                                    <div class="d-flex align-items-center gap-2 bg-light rounded-pill px-3 py-2">
                                        <span class="badge bg-info rounded-circle p-1">
                                            <i class="bx bx-time fs-6"></i>
                                        </span>
                                        <span class="fw-medium">Time</span>
                                        <small class="text-muted"><?= date('h:i A') ?></small>
                                    </div>
                                    
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

            </div>
        </div>
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Sales Chart
const trendMonths = <?= json_encode(array_column($trend, 'month')) ?>;
const trendNetRevenue = <?= json_encode(array_column($trend, 'net')) ?>;
const trendReturns = <?= json_encode(array_column($trend, 'returns')) ?>;

const ctx = document.getElementById('salesChart').getContext('2d');

const gradientNet = ctx.createLinearGradient(0, 0, 0, 400);
gradientNet.addColorStop(0, 'rgba(91, 115, 232, 0.3)');
gradientNet.addColorStop(1, 'rgba(91, 115, 232, 0.05)');

const salesChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: trendMonths,
        datasets: [
            {
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
                pointRadius: 5
            },
            {
                label: 'Returns',
                data: trendReturns,
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                borderColor: '#ffc107',
                borderWidth: 2,
                borderDash: [5, 5],
                tension: 0.4,
                fill: false,
                pointBackgroundColor: '#ffc107',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 4,
                hidden: trendReturns.every(v => v === 0)
            }
        ]
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
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ₹' + context.parsed.y.toLocaleString('en-IN');
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₹' + (value/1000).toFixed(0) + 'K';
                    }
                }
            }
        }
    }
});

// Cloud Timer Countdown
function updateCloudTimer() {
    <?php if ($show_timer_in_header && $expiry_timestamp_end_of_day): ?>
    const expiryTimestamp = <?= $expiry_timestamp_end_of_day ?> * 1000;
    const currentTimestamp = Date.now();
    let remainingSeconds = Math.floor((expiryTimestamp - currentTimestamp) / 1000);
    
    if (remainingSeconds <= 0) {
        window.location.href = 'cloud_renewal.php';
        return;
    }
    
    const days = Math.floor(remainingSeconds / (24 * 60 * 60));
    const hours = Math.floor((remainingSeconds % (24 * 60 * 60)) / (60 * 60));
    const minutes = Math.floor((remainingSeconds % (60 * 60)) / 60);
    const seconds = remainingSeconds % 60;
    
    const headerTimer = document.getElementById('headerTimer');
    if (headerTimer) {
        headerTimer.querySelector('.days').textContent = days.toString().padStart(2, '0');
        headerTimer.querySelector('.hours').textContent = hours.toString().padStart(2, '0');
        headerTimer.querySelector('.minutes').textContent = minutes.toString().padStart(2, '0');
        headerTimer.querySelector('.seconds').textContent = seconds.toString().padStart(2, '0');
    }
    
    const modalDays = document.getElementById('modalDaysLeft');
    if (modalDays) modalDays.textContent = days;
    <?php endif; ?>
}

updateCloudTimer();
setInterval(updateCloudTimer, 1000);

// Show 1-month modal
<?php if ($show_one_month_modal): ?>
document.addEventListener('DOMContentLoaded', function() {
    const oneMonthModal = new bootstrap.Modal(document.getElementById('oneMonthModal'));
    oneMonthModal.show();
});
<?php endif; ?>

// Auto-refresh dashboard every 5 minutes
setTimeout(function() {
    window.location.reload();
}, 300000);
</script>

</body>
</html>