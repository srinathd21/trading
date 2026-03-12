<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'warehouse_manager', 'shop_manager'])) {
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$current_business_id = $_SESSION['current_business_id'] ?? null;

if (!$current_business_id) {
    $_SESSION['error'] = "Please select a business first.";
    header('Location: select_shop.php');
    exit();
}

// Default date range (current month)
$start_date = date('Y-m-01');
$end_date = date('Y-m-t');

// Get filters
$filter_start_date = $_GET['start_date'] ?? $start_date;
$filter_end_date = $_GET['end_date'] ?? $end_date;
$shop_id = $_GET['shop_id'] ?? '';
$report_type = $_GET['report_type'] ?? 'summary';
$export_type = $_GET['export'] ?? ''; // excel, json, csv

// Get shops for filter
$shops = $pdo->prepare("
    SELECT id, shop_name, shop_code 
    FROM shops 
    WHERE business_id = ? 
    AND is_active = 1 
    ORDER BY shop_name
");
$shops->execute([$current_business_id]);
$shops = $shops->fetchAll();

// Function to get Non-GST invoice summary
function getNonGSTSummary($pdo, $business_id, $start_date, $end_date, $shop_id) {
    $params = [$business_id, $start_date, $end_date];
    $shop_condition = '';
    
    if ($shop_id && $shop_id !== 'all') {
        $shop_condition = " AND i.shop_id = ?";
        $params[] = $shop_id;
    }
    
    $query = "
        SELECT 
            DATE(i.created_at) as invoice_date,
            i.id as invoice_id,
            i.invoice_number,
            i.customer_type,
            i.payment_status,
            i.paid_amount,
            i.pending_amount,
            s.shop_name,
            c.name as customer_name,
            c.phone as customer_phone,
            SUM(ii.quantity) as total_items,
            SUM(ii.total_price) as subtotal,
            i.discount,
            i.overall_discount,
            i.total as invoice_total
        FROM invoices i
        INNER JOIN invoice_items ii ON i.id = ii.invoice_id
        INNER JOIN shops s ON i.shop_id = s.id
        INNER JOIN customers c ON i.customer_id = c.id
        WHERE i.business_id = ?
        AND DATE(i.created_at) BETWEEN ? AND ?
        AND i.gst_status = 0
        $shop_condition
        GROUP BY i.id, DATE(i.created_at)
        ORDER BY i.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get product-wise Non-GST sales
function getProductWiseNonGST($pdo, $business_id, $start_date, $end_date, $shop_id) {
    $params = [$business_id, $start_date, $end_date];
    $shop_condition = '';
    
    if ($shop_id && $shop_id !== 'all') {
        $shop_condition = " AND i.shop_id = ?";
        $params[] = $shop_id;
    }
    
    $query = "
        SELECT 
            p.id as product_id,
            p.product_name,
            p.product_code,
            p.unit_of_measure,
            c.category_name,
            s.subcategory_name,
            COUNT(DISTINCT i.id) as invoice_count,
            SUM(ii.quantity) as total_quantity,
            SUM(ii.total_price) as total_sales,
            ROUND(AVG(ii.unit_price), 2) as avg_unit_price,
            SUM(ii.profit) as total_profit,
            CASE 
                WHEN SUM(ii.total_price) > 0 
                THEN ROUND((SUM(ii.profit) / SUM(ii.total_price)) * 100, 2) 
                ELSE 0 
            END as profit_margin
        FROM invoice_items ii
        INNER JOIN invoices i ON ii.invoice_id = i.id
        INNER JOIN products p ON ii.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories s ON p.subcategory_id = s.id
        WHERE i.business_id = ?
        AND DATE(i.created_at) BETWEEN ? AND ?
        AND i.gst_status = 0
        $shop_condition
        GROUP BY p.id, p.product_name, p.product_code
        ORDER BY total_sales DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get customer-wise Non-GST sales
function getCustomerWiseNonGST($pdo, $business_id, $start_date, $end_date, $shop_id) {
    $params = [$business_id, $start_date, $end_date];
    $shop_condition = '';
    
    if ($shop_id && $shop_id !== 'all') {
        $shop_condition = " AND i.shop_id = ?";
        $params[] = $shop_id;
    }
    
    $query = "
        SELECT 
            c.id as customer_id,
            c.name as customer_name,
            c.phone as customer_phone,
            c.customer_type,
            COUNT(DISTINCT i.id) as invoice_count,
            COUNT(DISTINCT ii.product_id) as unique_products,
            SUM(ii.quantity) as total_items,
            SUM(i.total) as total_purchases,
            SUM(i.paid_amount) as total_paid,
            SUM(i.pending_amount) as total_pending,
            ROUND(AVG(i.total), 2) as avg_invoice_value,
            MAX(i.total) as max_invoice_value,
            MAX(DATE(i.created_at)) as last_purchase_date
        FROM invoices i
        INNER JOIN customers c ON i.customer_id = c.id
        INNER JOIN invoice_items ii ON i.id = ii.invoice_id
        WHERE i.business_id = ?
        AND DATE(i.created_at) BETWEEN ? AND ?
        AND i.gst_status = 0
        $shop_condition
        GROUP BY c.id, c.name, c.phone, c.customer_type
        ORDER BY total_purchases DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get daily Non-GST sales trend
function getDailyNonGSTTrend($pdo, $business_id, $start_date, $end_date, $shop_id) {
    $params = [$business_id, $start_date, $end_date];
    $shop_condition = '';
    
    if ($shop_id && $shop_id !== 'all') {
        $shop_condition = " AND i.shop_id = ?";
        $params[] = $shop_id;
    }
    
    $query = "
        SELECT 
            DATE(i.created_at) as sale_date,
            DAYNAME(i.created_at) as day_name,
            COUNT(DISTINCT i.id) as invoice_count,
            COUNT(DISTINCT i.customer_id) as unique_customers,
            SUM(ii.quantity) as total_items,
            SUM(i.total) as total_sales,
            SUM(i.discount) as total_discount,
            SUM(i.paid_amount) as total_collected,
            SUM(i.pending_amount) as total_pending,
            SUM(CASE WHEN i.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
            SUM(CASE WHEN i.payment_status = 'partial' THEN 1 ELSE 0 END) as partial_invoices,
            SUM(CASE WHEN i.payment_status = 'pending' THEN 1 ELSE 0 END) as pending_invoices
        FROM invoices i
        INNER JOIN invoice_items ii ON i.id = ii.invoice_id
        WHERE i.business_id = ?
        AND DATE(i.created_at) BETWEEN ? AND ?
        AND i.gst_status = 0
        $shop_condition
        GROUP BY DATE(i.created_at)
        ORDER BY sale_date
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get payment method breakdown
function getPaymentMethodBreakdown($pdo, $business_id, $start_date, $end_date, $shop_id) {
    $params = [$business_id, $start_date, $end_date];
    $shop_condition = '';
    
    if ($shop_id && $shop_id !== 'all') {
        $shop_condition = " AND i.shop_id = ?";
        $params[] = $shop_id;
    }
    
    $query = "
        SELECT 
            i.payment_method,
            COUNT(DISTINCT i.id) as invoice_count,
            SUM(i.total) as total_amount,
            SUM(i.cash_amount) as cash_amount,
            SUM(i.upi_amount) as upi_amount,
            SUM(i.bank_amount) as bank_amount,
            SUM(i.cheque_amount) as cheque_amount,
            SUM(i.credit_amount) as credit_amount
        FROM invoices i
        WHERE i.business_id = ?
        AND DATE(i.created_at) BETWEEN ? AND ?
        AND i.gst_status = 0
        $shop_condition
        GROUP BY i.payment_method
        ORDER BY total_amount DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Get reports based on type
if ($report_type === 'summary') {
    $invoice_summary = getNonGSTSummary($pdo, $current_business_id, $filter_start_date, $filter_end_date, $shop_id);
    $payment_methods = getPaymentMethodBreakdown($pdo, $current_business_id, $filter_start_date, $filter_end_date, $shop_id);
} elseif ($report_type === 'product') {
    $product_sales = getProductWiseNonGST($pdo, $current_business_id, $filter_start_date, $filter_end_date, $shop_id);
} elseif ($report_type === 'customer') {
    $customer_sales = getCustomerWiseNonGST($pdo, $current_business_id, $filter_start_date, $filter_end_date, $shop_id);
} elseif ($report_type === 'daily') {
    $daily_trend = getDailyNonGSTTrend($pdo, $current_business_id, $filter_start_date, $filter_end_date, $shop_id);
}

// Calculate totals for summary
$total_invoices = 0;
$total_sales = 0;
$total_items = 0;
$total_collected = 0;
$total_pending = 0;
$total_discount = 0;

if ($report_type === 'summary' && isset($invoice_summary)) {
    $total_invoices = count($invoice_summary);
    foreach ($invoice_summary as $row) {
        $total_sales += $row['invoice_total'];
        $total_items += $row['total_items'];
        $total_collected += $row['paid_amount'];
        $total_pending += $row['pending_amount'];
        $total_discount += $row['overall_discount'];
    }
}

// Export functionality
if ($export_type === 'excel') {
    exportToExcel($report_type, $filter_start_date, $filter_end_date, $shop_id);
    exit();
} elseif ($export_type === 'json') {
    exportToJSON($report_type, $filter_start_date, $filter_end_date, $shop_id);
    exit();
} elseif ($export_type === 'csv') {
    exportToCSV($report_type, $filter_start_date, $filter_end_date, $shop_id);
    exit();
}

// Export to CSV function
function exportToCSV($report_type, $start_date, $end_date, $shop_id) {
    global $pdo, $current_business_id, $shops;
    
    $filename = "non_gst_report_" . date('Y_m_d_H_i_s') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    
    $shop_name = "All Shops";
    if ($shop_id !== 'all') {
        foreach ($shops as $shop) {
            if ($shop['id'] == $shop_id) {
                $shop_name = $shop['shop_name'];
                break;
            }
        }
    }
    
    fputcsv($output, ['Non-GST Report - ' . ucfirst($report_type)]);
    fputcsv($output, ['Period: ' . date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date))]);
    fputcsv($output, ['Shop: ' . $shop_name]);
    fputcsv($output, ['Generated on: ' . date('d M Y h:i A')]);
    fputcsv($output, ['']);
    
    if ($report_type === 'summary') {
        $data = getNonGSTSummary($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        fputcsv($output, ['Date', 'Invoice No', 'Customer', 'Phone', 'Shop', 'Items', 'Subtotal', 'Discount', 'Total', 'Paid', 'Pending', 'Status']);
        
        $grand_total = 0;
        $grand_paid = 0;
        $grand_pending = 0;
        
        foreach ($data as $row) {
            fputcsv($output, [
                date('d M Y', strtotime($row['invoice_date'])),
                $row['invoice_number'],
                $row['customer_name'],
                $row['customer_phone'],
                $row['shop_name'],
                $row['total_items'],
                number_format($row['subtotal'], 2, '.', ''),
                number_format($row['overall_discount'], 2, '.', ''),
                number_format($row['invoice_total'], 2, '.', ''),
                number_format($row['paid_amount'], 2, '.', ''),
                number_format($row['pending_amount'], 2, '.', ''),
                $row['payment_status']
            ]);
            
            $grand_total += $row['invoice_total'];
            $grand_paid += $row['paid_amount'];
            $grand_pending += $row['pending_amount'];
        }
        
        fputcsv($output, ['']);
        fputcsv($output, ['GRAND TOTALS', '', '', '', '', '', '', '',
            number_format($grand_total, 2, '.', ''),
            number_format($grand_paid, 2, '.', ''),
            number_format($grand_pending, 2, '.', ''),
            ''
        ]);
        
        fputcsv($output, ['']);
        fputcsv($output, ['Total Invoices:', count($data)]);
        fputcsv($output, ['Total Sales:', '₹' . number_format($grand_total, 2, '.', '')]);
        fputcsv($output, ['Total Collected:', '₹' . number_format($grand_paid, 2, '.', '')]);
        fputcsv($output, ['Total Pending:', '₹' . number_format($grand_pending, 2, '.', '')]);
        
    } elseif ($report_type === 'product') {
        $data = getProductWiseNonGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        fputcsv($output, ['Product Code', 'Product Name', 'Category', 'Subcategory', 'Unit', 'Invoices', 'Qty Sold', 'Total Sales', 'Avg Price', 'Profit', 'Margin %']);
        
        $grand_qty = 0;
        $grand_sales = 0;
        $grand_profit = 0;
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['product_code'],
                $row['product_name'],
                $row['category_name'],
                $row['subcategory_name'],
                $row['unit_of_measure'],
                $row['invoice_count'],
                $row['total_quantity'],
                number_format($row['total_sales'], 2, '.', ''),
                number_format($row['avg_unit_price'], 2, '.', ''),
                number_format($row['total_profit'], 2, '.', ''),
                $row['profit_margin'] . '%'
            ]);
            
            $grand_qty += $row['total_quantity'];
            $grand_sales += $row['total_sales'];
            $grand_profit += $row['total_profit'];
        }
        
        fputcsv($output, ['']);
        fputcsv($output, ['GRAND TOTALS', '', '', '', '', '',
            $grand_qty,
            number_format($grand_sales, 2, '.', ''),
            '',
            number_format($grand_profit, 2, '.', ''),
            $grand_sales > 0 ? number_format(($grand_profit / $grand_sales) * 100, 2) . '%' : '0%'
        ]);
        
    } elseif ($report_type === 'customer') {
        $data = getCustomerWiseNonGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        fputcsv($output, ['Customer Name', 'Phone', 'Type', 'Invoices', 'Products', 'Items', 'Total Purchases', 'Paid', 'Pending', 'Avg Value', 'Last Purchase']);
        
        $grand_purchases = 0;
        $grand_paid = 0;
        $grand_pending = 0;
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['customer_name'],
                $row['customer_phone'],
                $row['customer_type'],
                $row['invoice_count'],
                $row['unique_products'],
                $row['total_items'],
                number_format($row['total_purchases'], 2, '.', ''),
                number_format($row['total_paid'], 2, '.', ''),
                number_format($row['total_pending'], 2, '.', ''),
                number_format($row['avg_invoice_value'], 2, '.', ''),
                $row['last_purchase_date'] ? date('d M Y', strtotime($row['last_purchase_date'])) : ''
            ]);
            
            $grand_purchases += $row['total_purchases'];
            $grand_paid += $row['total_paid'];
            $grand_pending += $row['total_pending'];
        }
        
        fputcsv($output, ['']);
        fputcsv($output, ['GRAND TOTALS', '', '', '', '', '',
            number_format($grand_purchases, 2, '.', ''),
            number_format($grand_paid, 2, '.', ''),
            number_format($grand_pending, 2, '.', ''),
            '',
            ''
        ]);
        
    } elseif ($report_type === 'daily') {
        $data = getDailyNonGSTTrend($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        fputcsv($output, ['Date', 'Day', 'Invoices', 'Unique Customers', 'Items', 'Sales', 'Discount', 'Collected', 'Pending']);
        
        $grand_invoices = 0;
        $grand_items = 0;
        $grand_sales = 0;
        $grand_discount = 0;
        $grand_collected = 0;
        $grand_pending = 0;
        
        foreach ($data as $row) {
            fputcsv($output, [
                date('d M Y', strtotime($row['sale_date'])),
                $row['day_name'],
                $row['invoice_count'],
                $row['unique_customers'],
                $row['total_items'],
                number_format($row['total_sales'], 2, '.', ''),
                number_format($row['total_discount'], 2, '.', ''),
                number_format($row['total_collected'], 2, '.', ''),
                number_format($row['total_pending'], 2, '.', '')
            ]);
            
            $grand_invoices += $row['invoice_count'];
            $grand_items += $row['total_items'];
            $grand_sales += $row['total_sales'];
            $grand_discount += $row['total_discount'];
            $grand_collected += $row['total_collected'];
            $grand_pending += $row['total_pending'];
        }
        
        fputcsv($output, ['']);
        fputcsv($output, ['GRAND TOTALS', '', $grand_invoices, '', $grand_items,
            number_format($grand_sales, 2, '.', ''),
            number_format($grand_discount, 2, '.', ''),
            number_format($grand_collected, 2, '.', ''),
            number_format($grand_pending, 2, '.', '')
        ]);
    }
    
    fclose($output);
    exit();
}

// Export to Excel function
function exportToExcel($report_type, $start_date, $end_date, $shop_id) {
    global $pdo, $current_business_id, $shops;
    
    $filename = "non_gst_report_" . date('Y_m_d') . ".xls";
    
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    
    $shop_name = "All Shops";
    if ($shop_id !== 'all') {
        foreach ($shops as $shop) {
            if ($shop['id'] == $shop_id) {
                $shop_name = $shop['shop_name'];
                break;
            }
        }
    }
    
    echo "<html><head><meta charset='UTF-8'>";
    echo "<style>";
    echo "td { padding: 5px; border: 1px solid #ddd; }";
    echo "th { background-color: #f2f2f2; padding: 8px; border: 1px solid #ddd; }";
    echo ".header { background-color: #4CAF50; color: white; font-weight: bold; }";
    echo ".total { background-color: #e8f5e9; font-weight: bold; }";
    echo "</style></head><body>";
    
    echo "<table border='1'>";
    
    echo "<tr><td colspan='10' class='header' style='text-align:center;'>";
    echo "<h2>Non-GST Report - " . ucfirst($report_type) . "</h2>";
    echo "<p>Period: " . date('d M Y', strtotime($start_date)) . " to " . date('d M Y', strtotime($end_date)) . "</p>";
    echo "<p>Shop: " . $shop_name . "</p>";
    echo "<p>Generated on: " . date('d M Y h:i A') . "</p>";
    echo "</td></tr>";
    
    if ($report_type === 'summary') {
        $data = getNonGSTSummary($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        echo "<tr>";
        echo "<th>Date</th><th>Invoice No</th><th>Customer</th><th>Phone</th><th>Shop</th>";
        echo "<th>Items</th><th>Subtotal</th><th>Discount</th><th>Total</th><th>Paid</th><th>Pending</th><th>Status</th>";
        echo "</tr>";
        
        $grand_total = 0; $grand_paid = 0; $grand_pending = 0;
        
        foreach ($data as $row) {
            echo "<tr>";
            echo "<td>" . date('d M Y', strtotime($row['invoice_date'])) . "</td>";
            echo "<td>" . $row['invoice_number'] . "</td>";
            echo "<td>" . $row['customer_name'] . "</td>";
            echo "<td>" . $row['customer_phone'] . "</td>";
            echo "<td>" . $row['shop_name'] . "</td>";
            echo "<td>" . $row['total_items'] . "</td>";
            echo "<td>" . number_format($row['subtotal'], 2) . "</td>";
            echo "<td>" . number_format($row['overall_discount'], 2) . "</td>";
            echo "<td>" . number_format($row['invoice_total'], 2) . "</td>";
            echo "<td>" . number_format($row['paid_amount'], 2) . "</td>";
            echo "<td>" . number_format($row['pending_amount'], 2) . "</td>";
            echo "<td>" . $row['payment_status'] . "</td>";
            echo "</tr>";
            
            $grand_total += $row['invoice_total'];
            $grand_paid += $row['paid_amount'];
            $grand_pending += $row['pending_amount'];
        }
        
        echo "<tr class='total'>";
        echo "<td colspan='8'><strong>GRAND TOTALS</strong></td>";
        echo "<td><strong>" . number_format($grand_total, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($grand_paid, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($grand_pending, 2) . "</strong></td>";
        echo "<td></td>";
        echo "</tr>";
        
    } elseif ($report_type === 'product') {
        $data = getProductWiseNonGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        echo "<tr>";
        echo "<th>Product Code</th><th>Product Name</th><th>Category</th><th>Subcategory</th><th>Unit</th>";
        echo "<th>Invoices</th><th>Qty Sold</th><th>Total Sales</th><th>Avg Price</th><th>Profit</th><th>Margin %</th>";
        echo "</tr>";
        
        $grand_qty = 0; $grand_sales = 0; $grand_profit = 0;
        
        foreach ($data as $row) {
            echo "<tr>";
            echo "<td>" . $row['product_code'] . "</td>";
            echo "<td>" . $row['product_name'] . "</td>";
            echo "<td>" . $row['category_name'] . "</td>";
            echo "<td>" . $row['subcategory_name'] . "</td>";
            echo "<td>" . $row['unit_of_measure'] . "</td>";
            echo "<td>" . $row['invoice_count'] . "</td>";
            echo "<td>" . $row['total_quantity'] . "</td>";
            echo "<td>" . number_format($row['total_sales'], 2) . "</td>";
            echo "<td>" . number_format($row['avg_unit_price'], 2) . "</td>";
            echo "<td>" . number_format($row['total_profit'], 2) . "</td>";
            echo "<td>" . $row['profit_margin'] . "%</td>";
            echo "</tr>";
            
            $grand_qty += $row['total_quantity'];
            $grand_sales += $row['total_sales'];
            $grand_profit += $row['total_profit'];
        }
        
        $avg_margin = $grand_sales > 0 ? ($grand_profit / $grand_sales) * 100 : 0;
        
        echo "<tr class='total'>";
        echo "<td colspan='6'><strong>GRAND TOTALS</strong></td>";
        echo "<td><strong>" . $grand_qty . "</strong></td>";
        echo "<td><strong>" . number_format($grand_sales, 2) . "</strong></td>";
        echo "<td></td>";
        echo "<td><strong>" . number_format($grand_profit, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($avg_margin, 2) . "%</strong></td>";
        echo "</tr>";
        
    } elseif ($report_type === 'customer') {
        $data = getCustomerWiseNonGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        echo "<tr>";
        echo "<th>Customer Name</th><th>Phone</th><th>Type</th><th>Invoices</th><th>Products</th><th>Items</th>";
        echo "<th>Total Purchases</th><th>Paid</th><th>Pending</th><th>Avg Value</th><th>Last Purchase</th>";
        echo "</tr>";
        
        $grand_purchases = 0; $grand_paid = 0; $grand_pending = 0;
        
        foreach ($data as $row) {
            echo "<tr>";
            echo "<td>" . $row['customer_name'] . "</td>";
            echo "<td>" . $row['customer_phone'] . "</td>";
            echo "<td>" . $row['customer_type'] . "</td>";
            echo "<td>" . $row['invoice_count'] . "</td>";
            echo "<td>" . $row['unique_products'] . "</td>";
            echo "<td>" . $row['total_items'] . "</td>";
            echo "<td>" . number_format($row['total_purchases'], 2) . "</td>";
            echo "<td>" . number_format($row['total_paid'], 2) . "</td>";
            echo "<td>" . number_format($row['total_pending'], 2) . "</td>";
            echo "<td>" . number_format($row['avg_invoice_value'], 2) . "</td>";
            echo "<td>" . ($row['last_purchase_date'] ? date('d M Y', strtotime($row['last_purchase_date'])) : '') . "</td>";
            echo "</tr>";
            
            $grand_purchases += $row['total_purchases'];
            $grand_paid += $row['total_paid'];
            $grand_pending += $row['total_pending'];
        }
        
        echo "<tr class='total'>";
        echo "<td colspan='6'><strong>GRAND TOTALS</strong></td>";
        echo "<td><strong>" . number_format($grand_purchases, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($grand_paid, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($grand_pending, 2) . "</strong></td>";
        echo "<td colspan='2'></td>";
        echo "</tr>";
        
    } elseif ($report_type === 'daily') {
        $data = getDailyNonGSTTrend($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        echo "<tr>";
        echo "<th>Date</th><th>Day</th><th>Invoices</th><th>Unique Customers</th><th>Items</th>";
        echo "<th>Sales</th><th>Discount</th><th>Collected</th><th>Pending</th>";
        echo "</tr>";
        
        $grand_invoices = 0; $grand_items = 0; $grand_sales = 0;
        $grand_discount = 0; $grand_collected = 0; $grand_pending = 0;
        
        foreach ($data as $row) {
            echo "<tr>";
            echo "<td>" . date('d M Y', strtotime($row['sale_date'])) . "</td>";
            echo "<td>" . $row['day_name'] . "</td>";
            echo "<td>" . $row['invoice_count'] . "</td>";
            echo "<td>" . $row['unique_customers'] . "</td>";
            echo "<td>" . $row['total_items'] . "</td>";
            echo "<td>" . number_format($row['total_sales'], 2) . "</td>";
            echo "<td>" . number_format($row['total_discount'], 2) . "</td>";
            echo "<td>" . number_format($row['total_collected'], 2) . "</td>";
            echo "<td>" . number_format($row['total_pending'], 2) . "</td>";
            echo "</tr>";
            
            $grand_invoices += $row['invoice_count'];
            $grand_items += $row['total_items'];
            $grand_sales += $row['total_sales'];
            $grand_discount += $row['total_discount'];
            $grand_collected += $row['total_collected'];
            $grand_pending += $row['total_pending'];
        }
        
        echo "<tr class='total'>";
        echo "<td colspan='2'><strong>GRAND TOTALS</strong></td>";
        echo "<td><strong>" . $grand_invoices . "</strong></td>";
        echo "<td></td>";
        echo "<td><strong>" . $grand_items . "</strong></td>";
        echo "<td><strong>" . number_format($grand_sales, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($grand_discount, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($grand_collected, 2) . "</strong></td>";
        echo "<td><strong>" . number_format($grand_pending, 2) . "</strong></td>";
        echo "</tr>";
    }
    
    echo "</table></body></html>";
    exit();
}

// Export to JSON function
function exportToJSON($report_type, $start_date, $end_date, $shop_id) {
    global $pdo, $current_business_id, $shops;
    
    $shop_name = "All Shops";
    if ($shop_id !== 'all') {
        foreach ($shops as $shop) {
            if ($shop['id'] == $shop_id) {
                $shop_name = $shop['shop_name'];
                break;
            }
        }
    }
    
    $report_data = [
        'metadata' => [
            'report_type' => 'non_gst_' . $report_type,
            'period' => [
                'start_date' => $start_date,
                'end_date' => $end_date,
                'display_period' => date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date))
            ],
            'shop' => $shop_name,
            'generated_on' => date('Y-m-d H:i:s'),
            'format' => 'JSON'
        ],
        'data' => []
    ];
    
    if ($report_type === 'summary') {
        $data = getNonGSTSummary($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        $payment_data = getPaymentMethodBreakdown($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        $report_data['data'] = $data;
        $report_data['payment_breakdown'] = $payment_data;
        $report_data['summary'] = [
            'total_invoices' => count($data),
            'total_sales' => array_sum(array_column($data, 'invoice_total')),
            'total_items' => array_sum(array_column($data, 'total_items')),
            'total_collected' => array_sum(array_column($data, 'paid_amount')),
            'total_pending' => array_sum(array_column($data, 'pending_amount')),
            'total_discount' => array_sum(array_column($data, 'overall_discount'))
        ];
        
    } elseif ($report_type === 'product') {
        $data = getProductWiseNonGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        $report_data['data'] = $data;
        $report_data['summary'] = [
            'total_products' => count($data),
            'total_quantity' => array_sum(array_column($data, 'total_quantity')),
            'total_sales' => array_sum(array_column($data, 'total_sales')),
            'total_profit' => array_sum(array_column($data, 'total_profit'))
        ];
        
    } elseif ($report_type === 'customer') {
        $data = getCustomerWiseNonGST($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        $report_data['data'] = $data;
        $report_data['summary'] = [
            'total_customers' => count($data),
            'total_purchases' => array_sum(array_column($data, 'total_purchases')),
            'total_paid' => array_sum(array_column($data, 'total_paid')),
            'total_pending' => array_sum(array_column($data, 'total_pending'))
        ];
        
    } elseif ($report_type === 'daily') {
        $data = getDailyNonGSTTrend($pdo, $current_business_id, $start_date, $end_date, $shop_id);
        
        $report_data['data'] = $data;
        $report_data['summary'] = [
            'total_days' => count($data),
            'total_invoices' => array_sum(array_column($data, 'invoice_count')),
            'total_sales' => array_sum(array_column($data, 'total_sales')),
            'total_collected' => array_sum(array_column($data, 'total_collected')),
            'total_pending' => array_sum(array_column($data, 'total_pending')),
            'total_discount' => array_sum(array_column($data, 'total_discount'))
        ];
    }
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="non_gst_report_' . date('Y_m_d') . '.json"');
    echo json_encode($report_data, JSON_PRETTY_PRINT);
    exit();
}
?>

<!doctype html>
<html lang="en">
<?php 
$page_title = "Non-GST Reports";
include('includes/head.php') 
?>
<style>
.stat-card {
    border-left: 4px solid;
    transition: transform 0.2s;
    background: linear-gradient(to right, #ffffff, #f8f9fa);
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.stat-card.primary { border-left-color: #0d6efd; }
.stat-card.success { border-left-color: #198754; }
.stat-card.warning { border-left-color: #fd7e14; }
.stat-card.info { border-left-color: #0dcaf0; }
.stat-card.danger { border-left-color: #dc3545; }
.stat-card.purple { border-left-color: #6f42c1; }

.summary-table th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.product-row {
    transition: background-color 0.2s;
}
.product-row:hover {
    background-color: rgba(13, 110, 253, 0.05) !important;
}

.chart-container {
    position: relative;
    height: 300px;
    width: 100%;
}

.payment-method-badge {
    padding: 8px 12px;
    border-radius: 6px;
    font-weight: 500;
}
.payment-method-badge.cash { background-color: #e3f2fd; color: #0d6efd; }
.payment-method-badge.upi { background-color: #e8f5e9; color: #198754; }
.payment-method-badge.bank { background-color: #fff3e0; color: #fd7e14; }
.payment-method-badge.cheque { background-color: #f3e5f5; color: #6f42c1; }
.payment-method-badge.credit { background-color: #fce4e4; color: #dc3545; }

.progress-sm {
    height: 6px;
}

.customer-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}
.customer-badge.retail { background-color: #e7f5ff; color: #0d6efd; }
.customer-badge.wholesale { background-color: #e8f5e9; color: #198754; }

.export-dropdown {
    position: absolute;
    right: 0;
    left: auto;
    min-width: 180px;
}

.overall-summary {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border-left: 4px solid #6f42c1;
}

.table-total-row {
    background-color: #e8f4ff !important;
    font-weight: bold;
}

@media print {
    .no-print {
        display: none !important;
    }
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
    table {
        font-size: 11px !important;
    }
}
</style>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include('includes/topbar.php') ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php') ?>
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
                                <i class="bx bx-receipt me-2"></i> Non-GST Reports & Analytics
                                <small class="text-muted ms-2">
                                    <i class="bx bx-store me-1"></i>
                                    <?= htmlspecialchars($_SESSION['current_business_name'] ?? 'Business') ?>
                                </small>
                            </h4>
                            <div class="export-options position-relative">
                                <div class="btn-group no-print">
                                    <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="bx bx-download me-1"></i> Export
                                    </button>
                                    <div class="dropdown-menu export-dropdown">
                                        <a class="dropdown-item" href="#" onclick="window.print()">
                                            <i class="bx bx-printer me-2"></i> Print Report
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <h6 class="dropdown-header">Download As</h6>
                                        <a class="dropdown-item export-link" href="#" data-type="csv">
                                            <i class="bx bx-file me-2 text-success"></i> CSV (.csv)
                                        </a>
                                        <a class="dropdown-item export-link" href="#" data-type="excel">
                                            <i class="bx bx-file me-2 text-success"></i> Excel (.xls)
                                        </a>
                                        <a class="dropdown-item export-link" href="#" data-type="json">
                                            <i class="bx bx-code me-2 text-info"></i> JSON (.json)
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="bx bx-filter-alt me-2"></i> Report Filters
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="GET" id="reportForm" class="row g-3">
                                    <input type="hidden" name="export" id="exportType">
                                    <div class="col-md-3">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" 
                                               value="<?= htmlspecialchars($filter_start_date) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" 
                                               value="<?= htmlspecialchars($filter_end_date) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Shop/Location</label>
                                        <select name="shop_id" class="form-select">
                                            <option value="all">All Shops</option>
                                            <?php foreach ($shops as $shop): ?>
                                            <option value="<?= $shop['id'] ?>" <?= $shop_id == $shop['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($shop['shop_name']) ?> (<?= $shop['shop_code'] ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Report Type</label>
                                        <select name="report_type" class="form-select" onchange="this.form.submit()">
                                            <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Invoice Summary</option>
                                            <option value="product" <?= $report_type === 'product' ? 'selected' : '' ?>>Product Wise Sales</option>
                                            <option value="customer" <?= $report_type === 'customer' ? 'selected' : '' ?>>Customer Wise Sales</option>
                                            <option value="daily" <?= $report_type === 'daily' ? 'selected' : '' ?>>Daily Sales Trend</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bx bx-refresh me-1"></i> Generate Report
                                            </button>
                                            <a href="non_gst_report.php" class="btn btn-outline-secondary">
                                                <i class="bx bx-reset me-1"></i> Reset Filters
                                            </a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Stats Cards -->
                <?php if ($report_type === 'summary' && $total_invoices > 0): ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card primary shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Total Invoices</h6>
                                        <h3 class="mb-0"><?= $total_invoices ?></h3>
                                        <small class="text-muted">Non-GST Invoices</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-primary bg-opacity-10 rounded">
                                            <i class="bx bx-receipt text-primary font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stat-card success shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Total Sales</h6>
                                        <h3 class="mb-0">₹<?= number_format($total_sales, 2) ?></h3>
                                        <small class="text-muted"><?= $total_items ?> items sold</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-success bg-opacity-10 rounded">
                                            <i class="bx bx-rupee text-success font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stat-card info shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Amount Collected</h6>
                                        <h3 class="mb-0">₹<?= number_format($total_collected, 2) ?></h3>
                                        <small class="text-muted"><?= $total_sales > 0 ? number_format(($total_collected / $total_sales) * 100, 1) : 0 ?>% of total</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-info bg-opacity-10 rounded">
                                            <i class="bx bx-wallet text-info font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stat-card warning shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-2">Pending Amount</h6>
                                        <h3 class="mb-0">₹<?= number_format($total_pending, 2) ?></h3>
                                        <small class="text-muted"><?= $total_sales > 0 ? number_format(($total_pending / $total_sales) * 100, 1) : 0 ?>% of total</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-warning bg-opacity-10 rounded">
                                            <i class="bx bx-time text-warning font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Content -->
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bx bx-file me-2"></i>
                                        <?php 
                                        $titles = [
                                            'summary' => 'Non-GST Invoice Summary',
                                            'product' => 'Product Wise Non-GST Sales',
                                            'customer' => 'Customer Wise Non-GST Sales',
                                            'daily' => 'Daily Non-GST Sales Trend'
                                        ];
                                        echo $titles[$report_type];
                                        ?>
                                    </h5>
                                    <div class="text-muted">
                                        Period: <?= date('d M Y', strtotime($filter_start_date)) ?> - <?= date('d M Y', strtotime($filter_end_date)) ?>
                                        <?php if ($shop_id !== 'all'): ?>
                                        | Shop: <?= htmlspecialchars($shops[array_search($shop_id, array_column($shops, 'id'))]['shop_name'] ?? 'All') ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                
                                <?php if ($report_type === 'summary'): ?>
                                
                                <!-- Payment Method Breakdown -->
                                <?php if (!empty($payment_methods)): ?>
                                <div class="row mb-4">
                                    <?php foreach ($payment_methods as $method): ?>
                                    <div class="col-md-2 col-6 mb-3">
                                        <div class="payment-method-badge <?= $method['payment_method'] ?> text-center">
                                            <div class="fw-bold text-uppercase mb-1"><?= $method['payment_method'] ?></div>
                                            <h5 class="mb-1">₹<?= number_format($method['total_amount'], 2) ?></h5>
                                            <small><?= $method['invoice_count'] ?> invoices</small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Invoice Summary Table -->
                                <div class="table-responsive">
                                    <table class="table table-hover summary-table" id="summaryTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Invoice No.</th>
                                                <th>Customer</th>
                                                <th>Phone</th>
                                                <th>Shop</th>
                                                <th class="text-center">Items</th>
                                                <th class="text-end">Subtotal</th>
                                                <th class="text-end">Discount</th>
                                                <th class="text-end">Total</th>
                                                <th class="text-end">Paid</th>
                                                <th class="text-end">Pending</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($invoice_summary)): ?>
                                            <tr>
                                                <td colspan="12" class="text-center py-4">
                                                    <i class="bx bx-receipt fs-1 text-muted mb-2 d-block"></i>
                                                    <h5>No Non-GST invoices found</h5>
                                                    <p class="text-muted">No non-GST invoices for the selected period.</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($invoice_summary as $invoice): ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($invoice['invoice_date'])) ?></td>
                                                <td>
                                                    <a href="invoice_view.php?id=<?= $invoice['invoice_id'] ?>" 
                                                       class="text-decoration-none">
                                                        <strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars($invoice['customer_name']) ?></td>
                                                <td><?= $invoice['customer_phone'] ?: '-' ?></td>
                                                <td><?= htmlspecialchars($invoice['shop_name']) ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-secondary rounded-pill px-3">
                                                        <?= $invoice['total_items'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">₹<?= number_format($invoice['subtotal'], 2) ?></td>
                                                <td class="text-end">₹<?= number_format($invoice['overall_discount'], 2) ?></td>
                                                <td class="text-end"><strong>₹<?= number_format($invoice['invoice_total'], 2) ?></strong></td>
                                                <td class="text-end text-success">₹<?= number_format($invoice['paid_amount'], 2) ?></td>
                                                <td class="text-end text-danger">₹<?= number_format($invoice['pending_amount'], 2) ?></td>
                                                <td>
                                                    <?php if ($invoice['payment_status'] === 'paid'): ?>
                                                    <span class="badge bg-success">Paid</span>
                                                    <?php elseif ($invoice['payment_status'] === 'partial'): ?>
                                                    <span class="badge bg-warning">Partial</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-danger">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Overall Totals Row -->
                                            <tr class="table-total-row">
                                                <td colspan="8" class="text-end"><strong>OVERALL TOTALS:</strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_sales, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_collected, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($total_pending, 2) ?></strong></td>
                                                <td></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Overall Summary Section -->
                                <?php if ($total_invoices > 0): ?>
                                <div class="row mt-4">
                                    <div class="col-md-8 mx-auto">
                                        <div class="overall-summary">
                                            <h6 class="mb-3"><i class="bx bx-stats me-2"></i> Overall Summary</h6>
                                            <div class="row">
                                                <div class="col-md-4 text-center">
                                                    <div class="p-3">
                                                        <div class="text-muted mb-1">Average Invoice Value</div>
                                                        <h4 class="mb-0 text-primary">₹<?= number_format($total_sales / $total_invoices, 2) ?></h4>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-center">
                                                    <div class="p-3">
                                                        <div class="text-muted mb-1">Collection Rate</div>
                                                        <h4 class="mb-0 text-success"><?= $total_sales > 0 ? number_format(($total_collected / $total_sales) * 100, 1) : 0 ?>%</h4>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-center">
                                                    <div class="p-3">
                                                        <div class="text-muted mb-1">Average Items/Invoice</div>
                                                        <h4 class="mb-0 text-info"><?= $total_invoices > 0 ? number_format($total_items / $total_invoices, 1) : 0 ?></h4>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-12">
                                                    <div class="progress progress-sm">
                                                        <?php 
                                                        $paid_percent = $total_sales > 0 ? ($total_collected / $total_sales) * 100 : 0;
                                                        $pending_percent = $total_sales > 0 ? ($total_pending / $total_sales) * 100 : 0;
                                                        ?>
                                                        <div class="progress-bar bg-success" style="width: <?= $paid_percent ?>%">Paid</div>
                                                        <div class="progress-bar bg-warning" style="width: <?= $pending_percent ?>%">Pending</div>
                                                    </div>
                                                    <div class="d-flex justify-content-between mt-2 small">
                                                        <span class="text-success">Paid: <?= number_format($paid_percent, 1) ?>%</span>
                                                        <span class="text-warning">Pending: <?= number_format($pending_percent, 1) ?>%</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php elseif ($report_type === 'product'): ?>
                                
                                <!-- Product Wise Sales Table -->
                                <div class="table-responsive">
                                    <table class="table table-hover" id="productTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Product Code</th>
                                                <th>Product Name</th>
                                                <th>Category</th>
                                                <th>Subcategory</th>
                                                <th>Unit</th>
                                                <th class="text-center">Invoices</th>
                                                <th class="text-center">Qty Sold</th>
                                                <th class="text-end">Total Sales</th>
                                                <th class="text-end">Avg Price</th>
                                                <th class="text-end">Profit</th>
                                                <th class="text-center">Margin %</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($product_sales)): ?>
                                            <tr>
                                                <td colspan="11" class="text-center py-4">
                                                    <i class="bx bx-package fs-1 text-muted mb-2 d-block"></i>
                                                    <h5>No product sales found</h5>
                                                    <p class="text-muted">No non-GST product sales for the selected period.</p>
                                                </td>
                                            </tr>
                                            <?php else: 
                                            $grand_qty = 0; $grand_sales = 0; $grand_profit = 0;
                                            ?>
                                            <?php foreach ($product_sales as $product): 
                                            $grand_qty += $product['total_quantity'];
                                            $grand_sales += $product['total_sales'];
                                            $grand_profit += $product['total_profit'];
                                            ?>
                                            <tr class="product-row">
                                                <td>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($product['product_code'] ?: '-') ?></span>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($product['product_name']) ?></strong>
                                                </td>
                                                <td><?= htmlspecialchars($product['category_name'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars($product['subcategory_name'] ?: '-') ?></td>
                                                <td><?= $product['unit_of_measure'] ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-info rounded-pill px-3">
                                                        <?= $product['invoice_count'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary px-3">
                                                        <?= number_format($product['total_quantity']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">₹<?= number_format($product['total_sales'], 2) ?></td>
                                                <td class="text-end">₹<?= number_format($product['avg_unit_price'], 2) ?></td>
                                                <td class="text-end <?= $product['total_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                    ₹<?= number_format($product['total_profit'], 2) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php 
                                                    $margin_class = 'secondary';
                                                    $margin = $product['profit_margin'];
                                                    if ($margin >= 20) $margin_class = 'success';
                                                    elseif ($margin >= 10) $margin_class = 'info';
                                                    elseif ($margin > 0) $margin_class = 'primary';
                                                    elseif ($margin < 0) $margin_class = 'danger';
                                                    ?>
                                                    <span class="badge bg-<?= $margin_class ?> bg-opacity-10 text-<?= $margin_class ?> px-3 py-1">
                                                        <?= number_format($margin, 2) ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Overall Totals Row -->
                                            <?php $avg_margin = $grand_sales > 0 ? ($grand_profit / $grand_sales) * 100 : 0; ?>
                                            <tr class="table-total-row">
                                                <td colspan="6" class="text-end"><strong>OVERALL TOTALS:</strong></td>
                                                <td class="text-center"><strong><?= number_format($grand_qty) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_sales, 2) ?></strong></td>
                                                <td class="text-end"></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_profit, 2) ?></strong></td>
                                                <td class="text-center"><strong><?= number_format($avg_margin, 2) ?>%</strong></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php elseif ($report_type === 'customer'): ?>
                                
                                <!-- Customer Wise Sales Table -->
                                <div class="table-responsive">
                                    <table class="table table-hover" id="customerTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Customer Name</th>
                                                <th>Phone</th>
                                                <th>Type</th>
                                                <th class="text-center">Invoices</th>
                                                <th class="text-center">Products</th>
                                                <th class="text-center">Items</th>
                                                <th class="text-end">Total Purchases</th>
                                                <th class="text-end">Paid</th>
                                                <th class="text-end">Pending</th>
                                                <th class="text-end">Avg Value</th>
                                                <th>Last Purchase</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($customer_sales)): ?>
                                            <tr>
                                                <td colspan="11" class="text-center py-4">
                                                    <i class="bx bx-user fs-1 text-muted mb-2 d-block"></i>
                                                    <h5>No customer sales found</h5>
                                                    <p class="text-muted">No non-GST customer sales for the selected period.</p>
                                                </td>
                                            </tr>
                                            <?php else: 
                                            $grand_purchases = 0; $grand_paid = 0; $grand_pending = 0;
                                            ?>
                                            <?php foreach ($customer_sales as $customer): 
                                            $grand_purchases += $customer['total_purchases'];
                                            $grand_paid += $customer['total_paid'];
                                            $grand_pending += $customer['total_pending'];
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($customer['customer_name']) ?></strong>
                                                </td>
                                                <td><?= $customer['customer_phone'] ?: '-' ?></td>
                                                <td>
                                                    <span class="customer-badge <?= $customer['customer_type'] ?>">
                                                        <?= ucfirst($customer['customer_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-info rounded-pill px-3">
                                                        <?= $customer['invoice_count'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-center"><?= $customer['unique_products'] ?></td>
                                                <td class="text-center"><?= $customer['total_items'] ?></td>
                                                <td class="text-end"><strong>₹<?= number_format($customer['total_purchases'], 2) ?></strong></td>
                                                <td class="text-end text-success">₹<?= number_format($customer['total_paid'], 2) ?></td>
                                                <td class="text-end text-danger">₹<?= number_format($customer['total_pending'], 2) ?></td>
                                                <td class="text-end">₹<?= number_format($customer['avg_invoice_value'], 2) ?></td>
                                                <td>
                                                    <?php if ($customer['last_purchase_date']): ?>
                                                    <small><?= date('d M Y', strtotime($customer['last_purchase_date'])) ?></small>
                                                    <?php else: ?>
                                                    -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Overall Totals Row -->
                                            <tr class="table-total-row">
                                                <td colspan="6" class="text-end"><strong>OVERALL TOTALS:</strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_purchases, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_paid, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_pending, 2) ?></strong></td>
                                                <td colspan="2"></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php elseif ($report_type === 'daily'): ?>
                                
                                <!-- Daily Sales Trend Table -->
                                <div class="table-responsive">
                                    <table class="table table-hover" id="dailyTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Day</th>
                                                <th class="text-center">Invoices</th>
                                                <th class="text-center">Unique Customers</th>
                                                <th class="text-center">Items</th>
                                                <th class="text-end">Sales</th>
                                                <th class="text-end">Discount</th>
                                                <th class="text-end">Collected</th>
                                                <th class="text-end">Pending</th>
                                                <th class="text-center">Payment Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($daily_trend)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-4">
                                                    <i class="bx bx-calendar fs-1 text-muted mb-2 d-block"></i>
                                                    <h5>No daily data found</h5>
                                                    <p class="text-muted">No non-GST sales for the selected period.</p>
                                                </td>
                                            </tr>
                                            <?php else: 
                                            $grand_invoices = 0; $grand_items = 0; $grand_sales = 0;
                                            $grand_discount = 0; $grand_collected = 0; $grand_pending = 0;
                                            ?>
                                            <?php foreach ($daily_trend as $day): 
                                            $grand_invoices += $day['invoice_count'];
                                            $grand_items += $day['total_items'];
                                            $grand_sales += $day['total_sales'];
                                            $grand_discount += $day['total_discount'];
                                            $grand_collected += $day['total_collected'];
                                            $grand_pending += $day['total_pending'];
                                            ?>
                                            <tr>
                                                <td><?= date('d M Y', strtotime($day['sale_date'])) ?></td>
                                                <td><?= $day['day_name'] ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-info rounded-pill px-3">
                                                        <?= $day['invoice_count'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-center"><?= $day['unique_customers'] ?></td>
                                                <td class="text-center"><?= $day['total_items'] ?></td>
                                                <td class="text-end">₹<?= number_format($day['total_sales'], 2) ?></td>
                                                <td class="text-end">₹<?= number_format($day['total_discount'], 2) ?></td>
                                                <td class="text-end text-success">₹<?= number_format($day['total_collected'], 2) ?></td>
                                                <td class="text-end text-danger">₹<?= number_format($day['total_pending'], 2) ?></td>
                                                <td class="text-center">
                                                    <?php if ($day['invoice_count'] > 0): ?>
                                                    <div class="small">
                                                        <span class="text-success">Paid: <?= $day['paid_invoices'] ?></span> |
                                                        <span class="text-warning">Partial: <?= $day['partial_invoices'] ?></span> |
                                                        <span class="text-danger">Pending: <?= $day['pending_invoices'] ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <!-- Overall Totals Row -->
                                            <tr class="table-total-row">
                                                <td colspan="2"><strong>OVERALL TOTALS</strong></td>
                                                <td class="text-center"><strong><?= $grand_invoices ?></strong></td>
                                                <td class="text-center"></td>
                                                <td class="text-center"><strong><?= $grand_items ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_sales, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_discount, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_collected, 2) ?></strong></td>
                                                <td class="text-end"><strong>₹<?= number_format($grand_pending, 2) ?></strong></td>
                                                <td></td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include('includes/footer.php') ?>
    </div>
</div>

<?php include('includes/rightbar.php') ?>
<?php include('includes/scripts.php') ?>

<script>
$(document).ready(function() {
    // Export functionality
    $('.export-link').click(function(e) {
        e.preventDefault();
        const exportType = $(this).data('type');
        
        let url = 'non_gst_report.php?';
        const params = new URLSearchParams();
        
        params.append('start_date', $('[name="start_date"]').val());
        params.append('end_date', $('[name="end_date"]').val());
        params.append('shop_id', $('[name="shop_id"]').val());
        params.append('report_type', $('[name="report_type"]').val());
        params.append('export', exportType);
        
        url += params.toString();
        window.location.href = url;
    });

    // Print optimization
    $('[onclick="window.print()"]').click(function() {
        $('body').addClass('print-mode');
        setTimeout(function() {
            $('body').removeClass('print-mode');
        }, 1000);
    });
});
</script>
</body>
</html>