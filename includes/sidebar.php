<?php
date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo '<div id="sidebar-menu"><ul class="metismenu list-unstyled" id="side-menu">
        <li class="menu-title">Welcome</li>
        <li><a href="login.php"><i class="bx bx-log-in-circle"></i> Login</a></li>
    </ul></div>';
    return;
}

$role = $_SESSION['role'] ?? '';
$user_id = (int)$_SESSION['user_id'];
$current_shop_id = $_SESSION['current_shop_id'] ?? null;
$current_shop_name = $_SESSION['current_shop_name'] ?? 'All Shops';

$is_admin = ($role === 'admin');
$is_shop_manager = in_array($role, ['admin', 'shop_manager']);
$is_accountant = in_array($role, ['admin', 'shop_manager', 'accountant']);
$is_seller = in_array($role, ['admin', 'shop_manager', 'seller', 'cashier']);
$is_stock_manager = in_array($role, ['admin', 'shop_manager', 'stock_manager', 'warehouse_manager']);

// Fetch user's accessible shops
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.shop_name, s.shop_code 
        FROM shops s
        LEFT JOIN user_shop_access usa ON s.id = usa.shop_id AND usa.user_id = ?
        WHERE s.is_active = 1 
          AND (usa.user_id = ? OR ? = 'admin')
        ORDER BY s.shop_name
    ");
    $stmt->execute([$user_id, $user_id, $role]);
    $user_shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $user_shops = [];
}
?>

<div id="sidebar-menu">
    <ul class="metismenu list-unstyled" id="side-menu">


        <li class="menu-title">Main Navigation</li>

        <li><a href="dashboard.php"><i class="bx bx-home-alt"></i> <span>Dashboard</span></a></li>

        <?php if ($is_seller): ?>
        <li>
            <a href="pos.php"><i class="bx bx-cart-add"></i> <span class="badge bg-success float-end">LIVE</span> <span>POS Billing</span></a>
        </li>
        <?php endif; ?>
        
        <li><a href="attendance.php"><i class="bx bx-user-check"></i> <span>Attendance</span></a></li>
      
        <!-- Sales Menu -->
        <?php if ($is_seller || $is_accountant): ?>
        
        <li><a href="invoices.php"><i class="bx bx-receipt"></i>  <span>All Invoices</span></a></li>
        <li><a href="quotations.php"><i class="bx bx-receipt"></i>  <span>Quotations</span></a></li>
        
        <?php endif; ?>

        <!-- Expenses - Only for authorized roles -->
        <?php if ($is_accountant || $is_shop_manager || $is_admin): ?>
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-money"></i> <span>Expenses</span>
            </a>
            <ul class="sub-menu">
                <li><a href="expense_add.php">Add Expenses</a></li>
                <li><a href="expenses.php">Manage Expenses</a></li>
            </ul>
        </li>
        <?php endif; ?>
        
        <?php if ($is_accountant || $is_shop_manager || $is_admin): ?>
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-money"></i> <span>Referrals</span>
            </a>
            <ul class="sub-menu">
                <li><a href="create_referral.php">Add Referral</a></li>
                <li><a href="referrals.php">Manage Referrals</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- Customer Management -->
        <?php if ($is_seller || $is_admin): ?>
        <li><a href="customers.php"><i class="bx bx-user"></i> <span>Customers</span></a></li>
        <?php endif; ?>

        <!-- Store Management -->
        <li class="menu-title">Store Management</li>
        
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-store"></i> <span>Stores</span>
            </a>
            <ul class="sub-menu">
                <li><a href="stores.php">Stores List</a></li>
                <li><a href="store_requirements.php">Store Requirements</a></li>
            </ul>
        </li>

        <!-- Inventory Management -->
        <?php if ($is_stock_manager): ?>
        <li class="menu-title">Inventory Management</li>
        
        <li><a href="manufacturers.php"><i class="bx bx-buildings me-2"></i><span>Supplier</span></a></li>
        
        <!-- Products Management -->
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-package"></i> <span>Products</span>
            </a>
            <ul class="sub-menu">
                <li><a href="categories.php">Categories</a></li>
                 <li><a href="subcategories.php">Subcategories</a></li>
                 <li><a href="product_add.php">Add New Product</a></li>
                <li><a href="products.php">All Products</a></li>
                <li><a href="product_import.php">Import Products</a></li>
                <li><a href="barcode.php">Generate Barcode</a></li>
            </ul>
        </li>

        <!-- Stock Management -->
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-archive"></i> <span>Stock Management</span>
            </a>
            <ul class="sub-menu">
               
                <li><a href="shop_stocks.php">Shop Stock</a></li>
            
                <li><a href="warehouse_stock.php">Warehouse Stock</a></li>
                <li><a href="stock_history.php">Stock History</a></li>
                <li><a href="stock_daily_report.php">O/C Stock</a></li>
                <li><a href="stock_adjustment.php">Stock Adjustment</a></li>
                <li><a href="stock_transfers.php">Stock Transfer</a></li>
            </ul>
        </li>
        
        <!-- Purchase Management -->
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-purchase-tag"></i> <span>Purchase</span>
            </a>
            <ul class="sub-menu">
                <li><a href="purchases.php">Purchase Orders</a></li>
                <li><a href="gst_input_credit.php">Gst Input Credits</a></li>
                <li><a href="purchase_requests.php">Purchase Requests</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- Reports -->
        <?php if ($is_shop_manager || $is_admin): ?>
        <li class="menu-title">Reports</li>
        
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-bar-chart-alt-2"></i> <span>Reports</span>
            </a>
            <ul class="sub-menu">
                <li><a href="report_daily.php">Daily Report</a></li>
                <li><a href="product_wise_sale_report.php">Product Wise sales Report</a></li>
                <li><a href="profit_loss.php">Profit & Loss</a></li>
                <li><a href="report_retail_sales.php">Retail Reports</a></li>
                <li><a href="report_wholesale_sales.php">Wholesale Reports</a></li>
                <li><a href="report_sales_summary.php">Sales Report</a></li>
                <li><a href="report_payment_methods.php">Payments Report</a></li>
                <li><a href="seller_report.php">Seller Report</a></li>
                   <li><a href="gst_report.php">Gst Reports</a></li>
                   <li><a href="non_gst_report.php ">Non Gst Reports</a></li>
                   
                <!--<li><a href="gst-reports.php">GST Reports</a></li>-->
            </ul>
        </li>
        <?php endif; ?>

        <!-- Admin Settings -->
        <?php if ($is_admin): ?>
        <li class="menu-title">Administration</li>
        
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-cog"></i> <span>Settings</span>
            </a>
            <ul class="sub-menu">
                <li><a href="users.php">User Management</a></li>
                 <li><a href="invoice-settings.php">Invoice Settings</a></li>
                 <li><a href="loyalty_settings.php">Loyalty Settings</a></li>
                <li><a href="shops.php">Shop Management</a></li>
                 <li><a href="gst_rates.php">GST Rate</a></li>
                <!--<li><a href="company_settings.php">Company Settings</a></li>-->
            </ul>
        </li>
        <?php endif; ?>

        <!-- Support -->
        <li class="menu-title">Support</li>
        
        <li>
            <a href="javascript: void(0);" class="has-arrow">
                <i class="bx bx-support"></i> <span>Need Help?</span>
            </a>
            <ul class="sub-menu">
                <li><a href="tel:+917200314099">Call: 72003 14099</a></li>
                <li><a href="tel:+919698094808">Call: 9003552650</a></li>
                <li><a href="https://wa.me/919003552650" target="_blank">WhatsApp Support</a></li>
            </ul>
        </li>

        <!-- Logout -->
        <li class="mt-3">
            <a href="logout.php" class="text-danger">
                <i class="bx bx-log-out-circle"></i> <span>Logout</span>
            </a>
        </li>
    </ul>
</div>

<style>
.shop-selector {
    background: rgba(255,255,255,0.05);
    border-radius: 12px;
    margin: 10px 15px 20px;
    padding: 12px;
    border: 1px solid rgba(255,255,255,0.1);
}
.shop-selector .dropdown-toggle {
    font-size: 0.9rem;
    padding: 8px 12px;
    background: transparent;
}
.shop-selector .dropdown-item {
    padding: 10px 15px;
}
.shop-selector .dropdown-item.active {
    background: #5b73e8 !important;
    border-radius: 8px;
}
.menu-title {
    color: #adb5bd !important;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 15px 20px 5px;
    font-weight: 600;
}
.metismenu a {
    padding: 12px 20px !important;
    font-size: 14px;
    font-weight: 500;
}
.sub-menu a {
    padding: 8px 20px 8px 50px !important;
    font-size: 13.5px;
}
.badge {
    font-size: 10px;
    padding: 3px 6px;
}
</style>

<script>
// Auto highlight current page in sidebar
document.addEventListener('DOMContentLoaded', function() {
    const currentPage = window.location.pathname.split('/').pop();
    const menuLinks = document.querySelectorAll('.metismenu a');
    
    menuLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && (href === currentPage || href.includes(currentPage))) {
            link.classList.add('active');
            
            // Highlight parent menu item
            const parentMenu = link.closest('.sub-menu');
            if (parentMenu) {
                const parentLink = parentMenu.previousElementSibling;
                if (parentLink && parentLink.classList.contains('has-arrow')) {
                    parentLink.classList.add('mm-active');
                }
            }
        }
    });
});
</script>