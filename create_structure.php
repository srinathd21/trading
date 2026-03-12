<?php
// create_all_files.php - Run once to generate ALL pages for Vidhya Traders

date_default_timezone_set('Asia/Kolkata');

$files = [
   
    'invoices.php' => "<?php include 'includes/auth.php'; ?>\n<h1>All Invoices</h1>",
    'sales_returns.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Sales Returns</h1>",

    // Masters
    'products.php' => "<?php include 'includes/auth.php'; ?>\n<h1>All Products</h1>",
    'product_add.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Add New Product</h1>",
    'product_edit.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Edit Product</h1>",
    'categories.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Categories</h1>",
    'gst_rates.php' => "<?php include 'includes/auth.php'; ?>\n<h1>GST Rates</h1>",
    'manufacturers.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Manufacturers</h1>",
    'customers.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Customers</h1>",

    // Purchase
    'request_list.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Purchase Requests</h1>",
    'request_add.php' => "<?php include 'includes/auth.php'; ?>\n<h1>New Purchase Request</h1>",
    'request_view.php' => "<?php include 'includes/auth.php'; ?>\n<h1>View Request</h1>",

    // Stock
    'stock.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Current Stock</h1>",
    'low_stock.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Low Stock Alert</h1>",
    'stock_transfers.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Stock Transfers</h1>",
    'packing_orders.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Packing Orders</h1>",

    // Expenses & Reports
    'expenses.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Expenses</h1>",
    'expense_add.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Add Expense</h1>",
    'report_daily.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Daily Sales Report</h1>",
    'report_monthly.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Monthly Report</h1>",
    'profit_loss.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Profit & Loss</h1>",
    'gst_report.php' => "<?php include 'includes/auth.php'; ?>\n<h1>GST Report</h1>",
    'stock_valuation.php' => "<?php include 'includes/auth.php'; ?>\n<h1>Stock Valuation</h1>",

    // Admin Pages
    'users.php' => "<?php include 'includes/auth.php'; role_required('admin'); ?>\n<h1>Users Management</h1>",
    'user_add.php' => "<?php include 'includes/auth.php'; role_required('admin'); ?>\n<h1>Add User</h1>",
    'user_edit.php' => "<?php include 'includes/auth.php'; role_required('admin'); ?>\n<h1>Edit User</h1>",
    'locations.php' => "<?php include 'includes/auth.php'; role_required('admin'); ?>\n<h1>Shop & Warehouse Locations</h1>",
    'company.php' => "<?php include 'includes/auth.php'; role_required('admin'); ?>\n<h1>Company Settings</h1>",
    'backup.php' => "<?php include 'includes/auth.php'; role_required('admin'); ?>\n<h1>Database Backup</h1>",
    'logs.php' => "<?php include 'includes/auth.php'; role_required('admin'); ?>\n<h1>Activity Log</h1>",
];



echo "<h2>Creating 35 + 6 Files for Vidhya Traders...</h2><pre>";

foreach ($includes as $path => $content) {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, "<?php\n// $path - Auto Generated\n?>\n" . $content);
    echo "Created: $path\n";
}

foreach ($files as $file => $content) {
    file_put_contents($file, "<?php\n// $file - Auto Generated on " . date('Y-m-d H:i') . "\n?>\n" . $content);
    echo "Created: $file\n";
}

echo "\n<h3 style='color:green'>All 41 files created successfully!</h3>";
echo "<p>Now upload your database and start building!</p>";
echo "<p><strong>Delete this file after use:</strong> <code>create_all_files.php</code></p>";
?>